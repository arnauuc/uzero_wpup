<?php

declare(strict_types=1);

use App\Domain\Artifacts\ArtifactException;
use App\Domain\Artifacts\ArtifactFetcher;
use App\Domain\Artifacts\ArtifactIntegrityFailed;
use App\Domain\Artifacts\ArtifactSettings;
use App\Domain\Artifacts\ArtifactStore;
use App\Domain\Artifacts\PackageSource;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\ComponentType;
use App\Models\Run;
use App\Models\RunArtifact;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

const ARTIFACT_TOKEN = 'lic-7f3a9c2e5b8d1f4a';

beforeEach(function (): void {
    config(['wpup.artifacts.dir' => sys_get_temp_dir().'/wpup-artifacts-'.bin2hex(random_bytes(6)), 'wpup.artifacts.max_bytes' => 64 * 1024]);
    app(SecretRedactor::class)->reset();
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg(artifactsRoot()));
});

/**
 * @param  array<string, string>  $files  entry name => contents
 */
function artifactZip(array $files = ['plugin-x/plugin-x.php' => '<?php // x', 'plugin-x/readme.txt' => 'x']): string
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

/**
 * @param  list<Response|Throwable>  $responses
 *
 * @param-out MockHandler $mock
 */
function artifactFetcher(array $responses, ?MockHandler &$mock = null): ArtifactFetcher
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);

    return new ArtifactFetcher(new Client(['handler' => $stack]), app(ArtifactStore::class), app(SecretRedactor::class), app(ArtifactSettings::class));
}

function premiumSource(string $url = 'https://updates.example.com/download/'.ARTIFACT_TOKEN.'/plugin-x.zip?license_key='.ARTIFACT_TOKEN.'&site=shop'): PackageSource
{
    return PackageSource::create(ComponentType::Plugin, 'plugin-x', '2.1.0', $url, app(SecretRedactor::class));
}

/** @return ArrayObject<int, string> */
function captureArtifactLogs(): ArrayObject
{
    /** @var ArrayObject<int, string> $logged */
    $logged = new ArrayObject;
    Event::listen(MessageLogged::class, static function (MessageLogged $e) use ($logged): void {
        $logged[] = $e->message.' '.json_encode($e->context);
    });

    return $logged;
}

it('stores the package per run with its checksum, private, and verifies it', function (): void {
    $root = artifactsRoot();
    $run = Run::factory()->create();
    $zip = artifactZip();

    $artifact = artifactFetcher([new Response(200, ['Content-Length' => (string) strlen($zip)], $zip)])->fetch($run->id, premiumSource());

    $row = RunArtifact::query()->sole();
    expect($artifact->sha256)->toBe(hash('sha256', $zip))
        ->and($artifact->sizeBytes)->toBe(strlen($zip))
        ->and($artifact->path)->toBe("{$root}/run-{$run->id}/plugin-plugin-x-2.1.0.zip")
        ->and(file_get_contents($artifact->path))->toBe($zip)
        ->and(fileperms($artifact->path) & 0777)->toBe(0600)
        ->and(fileperms(dirname($artifact->path)) & 0777)->toBe(0700)
        ->and($row->source_host)->toBe('updates.example.com')
        ->and($row->path)->toBe("run-{$run->id}/plugin-plugin-x-2.1.0.zip")
        ->and(glob(dirname($artifact->path).'/*.part'))->toBe([])
        ->and(app(ArtifactStore::class)->verify($artifact))->toBe($artifact);
});

it('reuses a stored artifact without downloading it again', function (): void {
    $run = Run::factory()->create();
    $zip = artifactZip();
    $fetcher = artifactFetcher([new Response(200, [], $zip)], $mock);

    $first = $fetcher->fetch($run->id, premiumSource());
    $second = $fetcher->fetch($run->id, premiumSource());

    expect($second)->toEqual($first)->and($mock->count())->toBe(0)->and(RunArtifact::query()->count())->toBe(1);
});

it('refuses to use an artifact that changed, went missing or was purged', function (): void {
    $run = Run::factory()->create();
    $store = app(ArtifactStore::class);
    $artifact = artifactFetcher([new Response(200, [], artifactZip())])->fetch($run->id, premiumSource());

    file_put_contents($artifact->path, 'X', FILE_APPEND);
    expect(fn () => $store->verify($artifact))->toThrow(ArtifactIntegrityFailed::class, 'no coincideix amb el SHA-256')
        // A re-fetch never replaces bytes that may already be on staging.
        ->and(fn () => artifactFetcher([new Response(200, [], artifactZip())])->fetch($run->id, premiumSource()))->toThrow(ArtifactIntegrityFailed::class);

    unlink($artifact->path);
    expect(fn () => $store->verify($artifact))->toThrow(ArtifactIntegrityFailed::class, 'no hi és');

    $store->purge(RunArtifact::query()->sole(), now()->toImmutable());
    $purged = $store->find($run->id, ComponentType::Plugin, 'plugin-x', '2.1.0');
    expect($purged?->purged)->toBeTrue()
        ->and(fn () => $store->verify($purged ?? $artifact))->toThrow(ArtifactIntegrityFailed::class, 'purgat');
});

it('follows HTTPS redirects and refuses a downgrade to HTTP', function (): void {
    $run = Run::factory()->create();
    $zip = artifactZip();

    artifactFetcher([new Response(302, ['Location' => 'https://cdn.example.net/signed/x.zip?sig=abcdef0123456789']), new Response(200, [], $zip)], $mock)
        ->fetch($run->id, premiumSource());
    expect((string) $mock->getLastRequest()?->getUri())->toBe('https://cdn.example.net/signed/x.zip?sig=abcdef0123456789')
        ->and(app(SecretRedactor::class)->redact('sig=abcdef0123456789'))->not->toContain('abcdef0123456789');

    $other = Run::factory()->create();
    expect(fn () => artifactFetcher([new Response(301, ['Location' => 'http://cdn.example.net/x.zip'])])->fetch($other->id, premiumSource()))
        ->toThrow(ArtifactException::class, 'no és HTTPS');
});

it('refuses non-HTTPS URLs, oversize packages and files that are not sane ZIPs', function (string $case): void {
    $run = Run::factory()->create();
    $big = str_repeat('a', 65 * 1024);

    $attempt = match ($case) {
        'http' => fn () => PackageSource::create(ComponentType::Plugin, 'plugin-x', '1.0', 'http://updates.example.com/x.zip', app(SecretRedactor::class)),
        'declared size' => fn () => artifactFetcher([new Response(200, ['Content-Length' => (string) strlen($big)], $big)])->fetch($run->id, premiumSource()),
        'streamed size' => fn () => artifactFetcher([new Response(200, [], $big)])->fetch($run->id, premiumSource()),
        'html' => fn () => artifactFetcher([new Response(200, [], '<html>Licence expired</html>')])->fetch($run->id, premiumSource()),
        'traversal' => fn () => artifactFetcher([new Response(200, [], artifactZip(['plugin-x/a.php' => 'a', 'plugin-x/../../evil.php' => 'x']))])->fetch($run->id, premiumSource()),
        'two roots' => fn () => artifactFetcher([new Response(200, [], artifactZip(['a/a.php' => 'a', 'b/b.php' => 'b']))])->fetch($run->id, premiumSource()),
        'http error' => fn () => artifactFetcher([new Response(403, [], 'denied')])->fetch($run->id, premiumSource()),
        default => throw new LogicException($case),
    };

    expect($attempt)->toThrow(ArtifactException::class)
        ->and(RunArtifact::query()->count())->toBe(0)
        ->and(glob(artifactsRoot().'/run-*/*') ?: [])->toBe([]);
})->with(['http', 'declared size', 'streamed size', 'html', 'traversal', 'two roots', 'http error']);

it('never shows the licence token in exceptions, logs or stored output', function (Response|Throwable $failure): void {
    $logged = captureArtifactLogs();
    $run = Run::factory()->create();
    $source = premiumSource();

    try {
        artifactFetcher([$failure])->fetch($run->id, $source);
        $message = '';
    } catch (ArtifactException $e) {
        $message = $e->getMessage()."\n".$e->getTraceAsString()."\n".($e->getPrevious()?->getMessage() ?? '');
    }

    // What a step would store in run_steps goes through the redactor.
    $stored = app(SecretRedactor::class)->redact('step failed for '.$source->url->reveal());
    $everything = $message."\n".implode("\n", $logged->getArrayCopy())."\n".$stored."\n".json_encode(RunArtifact::query()->get())."\n".print_r($source, true);

    expect($message)->toContain('plugin-x')
        ->and($everything)->not->toContain(ARTIFACT_TOKEN)
        ->and($everything)->not->toContain('license_key=');
})->with([
    'connection error' => fn () => new ConnectException('cURL error 28 for https://updates.example.com/download/'.ARTIFACT_TOKEN.'/plugin-x.zip?license_key='.ARTIFACT_TOKEN, new Request('GET', 'https://updates.example.com/')),
    'http 500' => fn () => new Response(500, [], 'error for key '.ARTIFACT_TOKEN),
    'redirect downgrade' => fn () => new Response(302, ['Location' => 'http://updates.example.com/download/'.ARTIFACT_TOKEN]),
    'malformed redirect' => fn () => new Response(302, ['Location' => 'https://updates.example.com:999999/'.ARTIFACT_TOKEN]),
    'not a zip' => fn () => new Response(200, [], 'Invalid licence '.ARTIFACT_TOKEN),
]);
