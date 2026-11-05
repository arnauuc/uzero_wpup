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
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Sleep;
use Tests\Contracts\BackupTargetContract;
use Tests\Support\FakeDropboxApi;

/*
 * The Dropbox API client of the Dropbox destination (UZEROWPUP-107), against
 * an in-process fake of the API: no network.
 */

function dropboxFake(): FakeDropboxApi
{
    return new FakeDropboxApi(sys_get_temp_dir().'/wpup-dropbox-'.bin2hex(random_bytes(6)));
}

function dropboxClient(FakeDropboxApi $fake, ?DropboxSettings $settings = null): DropboxClient
{
    $settings ??= FakeDropboxApi::settings();
    $http = new Client(['handler' => HandlerStack::create($fake)]);
    $redactor = app(SecretRedactor::class);

    return new DropboxClient($http, new DropboxTokenProvider($http, $fake->credential(), $redactor, $settings->tokenUrl), $redactor, $settings);
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
    $fake = dropboxFake();
    $client = dropboxClient($fake);

    $meta = $client->upload('/r/a.gz', BackupTargetContract::stream('dump'));
    $read = $client->download('/r/a.gz');

    expect($meta['size'])->toBe(4)
        ->and(stream_get_contents($read))->toBe('dump')
        ->and($fake->calls)->toBe(['oauth2/token', 'files/upload', 'files/download']);
});

it('uploads big files through an upload session in chunks of chunk_bytes', function (int $bytes, array $chunks): void {
    $fake = dropboxFake();
    $client = dropboxClient($fake);
    $source = fopen('php://temp', 'w+b');
    assert(is_resource($source));
    $block = random_bytes(1_048_576);
    for ($i = 0; $i < intdiv($bytes, 1_048_576); $i++) {
        fwrite($source, $block);
    }
    fwrite($source, str_repeat('x', $bytes % 1_048_576));
    rewind($source);
    $sha = hash('sha256', (string) stream_get_contents($source));
    rewind($source);

    $client->upload('/big/files.tar.gz', $source);

    expect($fake->chunkSizes)->toBe($chunks)
        ->and(array_values(array_filter($fake->calls, static fn (string $c): bool => str_starts_with($c, 'files/upload'))))
        ->toBe(['files/upload_session/start', ...array_fill(0, count($chunks) - 2, 'files/upload_session/append_v2'), 'files/upload_session/finish'])
        ->and(sha256Of($client->download('/big/files.tar.gz')))->toBe($sha);
})->with([
    'with a partial last chunk' => [10 * 1_048_576 + 5, [4_194_304, 4_194_304, 2 * 1_048_576 + 5]],
    'an exact multiple' => [8 * 1_048_576, [4_194_304, 4_194_304, 0]],
]);

it('refreshes an expired access token and retries the call', function (): void {
    $fake = dropboxFake();
    $client = dropboxClient($fake);
    $client->upload('/x.gz', BackupTargetContract::stream('x'));

    $fake->expireTokens();

    expect($client->metadata('/x.gz'))->toMatchArray(['size' => 1])
        ->and($fake->issuedTokens())->toBe(2)
        ->and(array_slice($fake->calls, -3))->toBe(['files/get_metadata', 'oauth2/token', 'files/get_metadata']);
});

it('retries 429 and 5xx answers honouring a capped Retry-After, then gives up', function (): void {
    Sleep::fake();
    $fake = dropboxFake();
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
    $fake = dropboxFake();
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
    $fake = dropboxFake();
    $client = dropboxClient($fake);

    $client->upload('/còpies/què €.gz', BackupTargetContract::stream('x'));

    expect(stream_get_contents($client->download('/còpies/què €.gz')))->toBe('x')
        ->and($fake->headerArgs[0])->toContain(substr((string) json_encode('/còpies/què €.gz', JSON_UNESCAPED_SLASHES), 1, -1))
        ->and(mb_check_encoding($fake->headerArgs[0], 'ASCII'))->toBeTrue();
});

it('never shows the tokens or the app secret in its errors', function (): void {
    $fake = dropboxFake();
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
    $credential = DropboxCredential::fromSecret(new Secret('{"app_key":"k","app_secret":"s3cr3t-app","refresh_token":"r3fr3sh-token"}'));

    expect($credential->appKey)->toBe('k')
        ->and($credential->appSecret->reveal())->toBe('s3cr3t-app')
        ->and(fn () => DropboxCredential::fromSecret(new Secret('{"app_key":"k","app_secret":"s3cr3t-app"}')))
        ->toThrow(DropboxException::class, '«refresh_token»')
        ->and(fn () => DropboxCredential::fromSecret(new Secret('not json s3cr3t-app')))
        ->toThrow(DropboxException::class, 'JSON');
});

it('accepts only chunk sizes Dropbox allows', function (int $bytes): void {
    expect(fn () => FakeDropboxApi::settings(chunkBytes: $bytes))->toThrow(InvalidArgumentException::class, 'multiple of 4 MiB');
})->with([0, 1_048_576, 5 * 1_048_576, 152 * 1_048_576]);
