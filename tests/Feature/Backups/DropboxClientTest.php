<?php

declare(strict_types=1);

use App\Domain\Backups\Dropbox\DropboxClient;
use App\Domain\Backups\Dropbox\DropboxCredential;
use App\Domain\Backups\Dropbox\DropboxException;
use App\Domain\Backups\Dropbox\DropboxSettings;
use App\Domain\Backups\Dropbox\DropboxTokenProvider;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretRedactor;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Tests\Contracts\BackupTargetContract;
use Tests\Support\FakeDropboxApi;
use Tests\Support\ShortReadStream;

/*
 * The Dropbox API client of the Dropbox destination (UZEROWPUP-107), against
 * an in-process fake of the API: no network.
 */

function dropboxClient(FakeDropboxApi $fake, ?DropboxSettings $settings = null): DropboxClient
{
    $settings ??= FakeDropboxApi::settings();
    $http = new Client(['handler' => HandlerStack::create($fake)]);
    $redactor = app(SecretRedactor::class);

    return new DropboxClient($http, new DropboxTokenProvider($http, $fake->credential(), $redactor, $settings), $redactor, $settings);
}

/**
 * $bytes pseudo-random bytes in a temporary file, and their SHA-256.
 *
 * @return array{0: string, 1: string}
 */
function dropboxSourceFile(int $bytes): array
{
    $file = (string) tempnam(sys_get_temp_dir(), 'wpup-dropbox-src');
    $out = fopen($file, 'wb');
    assert(is_resource($out));
    $block = random_bytes(1_048_576);
    for ($i = 0; $i < intdiv($bytes, 1_048_576); $i++) {
        fwrite($out, $block);
    }
    fwrite($out, str_repeat('x', $bytes % 1_048_576));
    fclose($out);

    return [$file, (string) hash_file('sha256', $file)];
}

/**
 * @param  resource  $stream
 */
function sha256Of($stream): string
{
    $hash = hash_init('sha256');
    hash_update_stream($hash, $stream);
    fclose($stream);

    return hash_final($hash);
}

it('uploads a small file in one request and downloads it back', function (): void {
    $fake = FakeDropboxApi::inTemp();
    $client = dropboxClient($fake);

    $meta = $client->upload('/r/a.gz', BackupTargetContract::stream('dump'));
    $read = $client->download('/r/a.gz');

    expect($meta['size'])->toBe(4)
        ->and(stream_get_contents($read))->toBe('dump')
        ->and($fake->calls)->toBe(['oauth2/token', 'files/upload', 'files/download']);
});

it('uploads big files through an upload session in chunks of chunk_bytes', function (int $bytes, array $chunks): void {
    $fake = FakeDropboxApi::inTemp();
    $client = dropboxClient($fake);
    [$file, $sha] = dropboxSourceFile($bytes);
    $source = fopen($file, 'rb');
    assert(is_resource($source));

    $client->upload('/big/files.tar.gz', $source);
    fclose($source);

    expect($fake->chunkSizes)->toBe($chunks)
        ->and(array_values(array_filter($fake->calls, static fn (string $c): bool => str_starts_with($c, 'files/upload'))))
        ->toBe(['files/upload_session/start', ...array_fill(0, count($chunks) - 2, 'files/upload_session/append_v2'), 'files/upload_session/finish'])
        ->and(sha256Of($client->download('/big/files.tar.gz')))->toBe($sha);
})->with([
    'with a partial last chunk' => [10 * 1_048_576 + 5, [4_194_304, 4_194_304, 2 * 1_048_576 + 5]],
    'an exact multiple' => [8 * 1_048_576, [4_194_304, 4_194_304, 0]],
]);

it('refreshes an expired access token and retries the call', function (): void {
    $fake = FakeDropboxApi::inTemp();
    $client = dropboxClient($fake);
    $client->upload('/x.gz', BackupTargetContract::stream('x'));

    $fake->expireTokens();

    expect($client->metadata('/x.gz'))->toMatchArray(['size' => 1])
        ->and($fake->issuedTokens())->toBe(2)
        ->and(array_slice($fake->calls, -3))->toBe(['files/get_metadata', 'oauth2/token', 'files/get_metadata']);
});

it('retries 429 and 5xx answers honouring a capped Retry-After, then gives up', function (): void {
    Sleep::fake();
    $fake = FakeDropboxApi::inTemp();
    $client = dropboxClient($fake, FakeDropboxApi::settings(maxRetries: 2, maxRetryAfter: 3));
    $fake->failNext('files/get_metadata', new Response(429, ['Retry-After' => '30'], '{"error_summary":"too_many_requests/"}'));
    $fake->failNext('files/get_metadata', new Response(503, [], 'busy'));

    expect($client->metadata('/missing.gz'))->toBeNull();
    Sleep::assertSequence([Sleep::for(3)->seconds(), Sleep::for(2)->seconds()]);

    foreach ([500, 502, 503] as $status) {
        $fake->failNext('files/get_metadata', new Response($status, [], 'down'));
    }
    expect(fn () => $client->metadata('/x.gz'))->toThrow(DropboxException::class, 'HTTP 503');
});

it('lists a folder across pages and reports missing paths', function (): void {
    $fake = FakeDropboxApi::inTemp();
    $client = dropboxClient($fake);
    foreach (['/s/a.gz', '/s/r/b.gz', '/s/r/c.gz', '/t.gz'] as $path) {
        $client->upload($path, BackupTargetContract::stream($path));
    }

    $paths = array_map(static fn (array $e): mixed => $e['path_display'], iterator_to_array($client->listFolder('/s', true), false));

    expect($paths)->toBe(['/s/a.gz', '/s/r', '/s/r/b.gz', '/s/r/c.gz'])
        ->and($fake->calls)->toContain('files/list_folder/continue')
        ->and($client->metadata('/nope'))->toBeNull()
        ->and(fn () => $client->download('/nope'))->toThrow(DropboxException::class, 'not_found')
        ->and(fn () => iterator_to_array($client->listFolder('/nope', false)))->toThrow(DropboxException::class, 'not_found');
});

it('escapes non-ASCII paths in the Dropbox-API-Arg header', function (): void {
    $fake = FakeDropboxApi::inTemp();
    $client = dropboxClient($fake);

    $client->upload('/còpies/què €.gz', BackupTargetContract::stream('x'));

    expect(stream_get_contents($client->download('/còpies/què €.gz')))->toBe('x')
        ->and($fake->headerArgs[0])->toContain(substr((string) json_encode('/còpies/què €.gz', JSON_UNESCAPED_SLASHES), 1, -1))
        ->and(mb_check_encoding($fake->headerArgs[0], 'ASCII'))->toBeTrue();
});

it('never shows the tokens or the app secret in its errors', function (): void {
    $fake = FakeDropboxApi::inTemp();
    $client = dropboxClient($fake, FakeDropboxApi::settings(maxRetries: 0));
    $client->metadata('/x');
    $token = (string) $fake->lastToken;
    $fake->failNext('files/get_metadata', new Response(500, [], "boom {$token} {$fake->appSecret} {$fake->refreshToken}"));

    try {
        $client->metadata('/x');
        $message = '';
    } catch (DropboxException $e) {
        $message = $e->getMessage();
    }

    $wrong = $fake->credential();
    $stale = dropboxClient($fake);
    $fake->refreshToken = 'rotated-'.bin2hex(random_bytes(8));
    $refused = fn () => $stale->metadata('/x');

    expect($message)->toContain('HTTP 500', Secret::MASK)
        ->and(str_contains($message, $token) || str_contains($message, $fake->appSecret) || str_contains($message, $wrong->refreshToken->reveal()))->toBeFalse()
        ->and($refused)->toThrow(DropboxException::class, 'invalid_grant')
        ->and(str_contains((string) json_encode($wrong), $fake->appSecret))->toBeFalse();
});

it('parses the stored credential without quoting it on errors', function (): void {
    $credential = DropboxCredential::fromSecret(new Secret('{"app_key":" k","app_secret":"s3cr3t-app\\n","refresh_token":"r3fr3sh-token\\n"}'));

    expect($credential->appKey)->toBe('k')
        ->and($credential->appSecret->reveal())->toBe('s3cr3t-app')
        ->and($credential->refreshToken->reveal())->toBe('r3fr3sh-token')
        ->and(fn () => DropboxCredential::fromSecret(new Secret('{"app_key":"k","app_secret":"s3cr3t app","refresh_token":"r"}')))
        ->toThrow(DropboxException::class, 'espais')
        ->and(fn () => DropboxCredential::fromSecret(new Secret('{"app_key":"k","app_secret":"s3cr3t-app"}')))
        ->toThrow(DropboxException::class, '«refresh_token»')
        ->and(fn () => DropboxCredential::fromSecret(new Secret('not json s3cr3t-app')))
        ->toThrow(DropboxException::class, 'JSON');
});

it('accepts only chunk sizes Dropbox allows', function (int $bytes): void {
    expect(fn () => FakeDropboxApi::settings(chunkBytes: $bytes))->toThrow(InvalidArgumentException::class, 'multiple of 4 MiB');
})->with([0, 1_048_576, 5 * 1_048_576, 152 * 1_048_576]);

it('resumes an upload session when a response was lost', function (string $endpoint, bool $seekable, int $bytes, int $calls): void {
    $fake = FakeDropboxApi::inTemp();
    $client = dropboxClient($fake);
    [$file, $sha] = dropboxSourceFile($bytes);
    $source = $seekable ? fopen($file, 'rb') : popen('cat '.escapeshellarg($file), 'r');
    assert(is_resource($source));
    $fake->loseNext($endpoint);

    $meta = $client->upload('/big/files.tar.gz', $source);
    $seekable ? fclose($source) : pclose($source);

    // The lost call is sent again; it is then answered with where to resume.
    expect($meta['size'])->toBe($bytes)
        ->and(sha256Of($client->download('/big/files.tar.gz')))->toBe($sha)
        ->and(array_count_values($fake->calls)[$endpoint])->toBe($calls);
})->with([
    'append, seekable' => ['files/upload_session/append_v2', true, 12 * 1_048_576 + 7, 3],
    'append, not seekable' => ['files/upload_session/append_v2', false, 12 * 1_048_576 + 7, 3],
    'finish' => ['files/upload_session/finish', true, 12 * 1_048_576 + 7, 2],
    'single upload' => ['files/upload', true, 1_048_576, 2],
]);

it('moves, copies, deletes and creates folders, and classifies their errors', function (): void {
    $fake = FakeDropboxApi::inTemp();
    $client = dropboxClient($fake);
    $client->upload('/a.gz', BackupTargetContract::stream('aaa'));
    $client->upload('/other.gz', BackupTargetContract::stream('o'));

    $client->copy('/a.gz', '/b.gz');
    $client->move('/a.gz', '/c/a.gz');
    $client->createFolder('/d');
    $client->delete('/b.gz');
    $error = static function (Closure $call): DropboxException {
        try {
            $call();
        } catch (DropboxException $e) {
            return $e;
        }

        throw new LogicException('No error.');
    };

    expect($client->metadata('/a.gz'))->toBeNull()
        ->and($client->metadata('/b.gz'))->toBeNull()
        ->and($client->metadata('/c/a.gz'))->toMatchArray(['size' => 3])
        ->and($client->metadata('/d'))->toMatchArray(['.tag' => 'folder'])
        ->and($error(fn () => $client->move('/missing.gz', '/x.gz'))->isNotFound())->toBeTrue()
        ->and($error(fn () => $client->move('/other.gz', '/c/a.gz'))->isDestinationConflict())->toBeTrue()
        ->and($error(fn () => $client->copy('/other.gz', '/c/a.gz'))->isDestinationConflict())->toBeTrue()
        ->and($error(fn () => $client->delete('/missing.gz'))->isNotFound())->toBeTrue()
        ->and($error(fn () => $client->createFolder('/d'))->isFolderConflict())->toBeTrue()
        ->and($error(fn () => $client->createFolder('/other.gz'))->isFolderConflict())->toBeFalse();
});

it('treats a move or copy that happened before a lost response as done', function (string $endpoint): void {
    $fake = FakeDropboxApi::inTemp();
    $client = dropboxClient($fake);
    $client->upload('/a.gz', BackupTargetContract::stream('aaa'));
    $fake->loseNext($endpoint);

    $endpoint === 'files/move_v2' ? $client->move('/a.gz', '/b.gz') : $client->copy('/a.gz', '/b.gz');

    expect($client->metadata('/b.gz'))->toMatchArray(['size' => 3])
        ->and($client->metadata('/a.gz') === null)->toBe($endpoint === 'files/move_v2');
})->with(['files/move_v2', 'files/copy_v2']);

it('refuses arguments that are not UTF-8 without sending anything', function (): void {
    $fake = FakeDropboxApi::inTemp();
    $client = dropboxClient($fake);

    expect(fn () => $client->upload("/a\xff.gz", BackupTargetContract::stream('x')))->toThrow(DropboxException::class, 'JSON')
        ->and(fn () => $client->metadata("/a\xff.gz"))->toThrow(DropboxException::class, 'JSON')
        ->and($fake->calls)->toBe([]);
});

it('retries the token endpoint when it is cut off or busy', function (): void {
    Sleep::fake();
    $fake = FakeDropboxApi::inTemp();
    $client = dropboxClient($fake, FakeDropboxApi::settings(maxRetries: 2, maxRetryAfter: 5));
    $fake->failNext('oauth2/token', new ConnectException('Connection refused', new Request('POST', FakeDropboxApi::BASE.'oauth2/token')));
    $fake->failNext('oauth2/token', new Response(503, ['Retry-After' => '4'], 'busy'));

    expect($client->metadata('/x'))->toBeNull()
        ->and(array_count_values($fake->calls)['oauth2/token'])->toBe(3);
    Sleep::assertSequence([Sleep::for(1)->seconds(), Sleep::for(4)->seconds()]);
});

it('logs every retried failure without secrets', function (): void {
    Sleep::fake();
    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $e) use (&$logged): void {
        $logged[] = $e->level.' '.$e->message.' '.json_encode($e->context, JSON_UNESCAPED_SLASHES);
    });
    $fake = FakeDropboxApi::inTemp();
    $client = dropboxClient($fake);
    $client->metadata('/x');
    $token = (string) $fake->lastToken;
    $fake->failNext('files/get_metadata', new Response(500, [], "boom {$token} {$fake->appSecret}"));
    $fake->loseNext('files/move_v2');
    $client->upload('/a.gz', BackupTargetContract::stream('a'));

    $client->metadata('/x');
    $client->move('/a.gz', '/b.gz');

    $all = implode("\n", $logged);
    expect($logged)->toHaveCount(3)
        ->and($all)->toContain('files/get_metadata', 'HTTP 500', 'files/move_v2', 'already moved')
        ->and(str_contains($all, $token) || str_contains($all, $fake->appSecret) || str_contains($all, 'boom'))->toBeFalse();
});

it('resumes a cut download from what already arrived', function (bool $ignoreRange): void {
    $fake = FakeDropboxApi::inTemp();
    $client = dropboxClient($fake);
    [$file, $sha] = dropboxSourceFile(9 * 1_048_576 + 3);
    $source = fopen($file, 'rb');
    assert(is_resource($source));
    $client->upload('/big.tar.gz', $source);
    fclose($source);
    $fake->ignoreRange = $ignoreRange;
    $fake->cutNext('files/download', 3 * 1_048_576);

    expect(sha256Of($client->download('/big.tar.gz')))->toBe($sha)
        ->and($fake->ranges)->toBe($ignoreRange ? [] : ['bytes=3145728-'])
        ->and(array_count_values($fake->calls)['files/download'])->toBe(2);
})->with(['with Range' => false, 'when the server ignores Range' => true]);

it('fills every chunk of a stream that gives short reads', function (): void {
    $fake = FakeDropboxApi::inTemp();
    $client = dropboxClient($fake);
    $contents = random_bytes(9 * 1_048_576 + 3);
    $source = ShortReadStream::open($contents);

    $client->upload('/short.gz', $source);
    fclose($source);

    expect($fake->chunkSizes)->toBe([4_194_304, 4_194_304, 1_048_579])
        ->and(sha256Of($client->download('/short.gz')))->toBe(hash('sha256', $contents));
});

it('refuses a 200 whose body is not a JSON object, without quoting it', function (): void {
    $fake = FakeDropboxApi::inTemp();
    $client = dropboxClient($fake);
    $client->metadata('/x');
    $fake->failNext('files/get_metadata', new Response(200, [], '<html>proxy page with s3cr3t</html>'));

    expect(fn () => $client->metadata('/x'))
        ->toThrow(fn (DropboxException $e) => expect($e->getMessage())->toContain('objecte JSON')->and(str_contains($e->getMessage(), 's3cr3t'))->toBeFalse());
});

it('does not take a different file of the same size for a copy that already happened', function (): void {
    $fake = FakeDropboxApi::inTemp();
    $client = dropboxClient($fake);
    $client->upload('/a.gz', BackupTargetContract::stream('aaa'));
    $client->upload('/b.gz', BackupTargetContract::stream('bbb'));
    $fake->failNext('files/copy_v2', new Response(500, [], 'down'));

    expect(fn () => $client->copy('/a.gz', '/b.gz'))->toThrow(DropboxException::class, 'to/conflict')
        ->and(FakeDropboxApi::contentHash($fake->dir.'/files/a.gz'))->not->toBe(FakeDropboxApi::contentHash($fake->dir.'/files/b.gz'));
});
