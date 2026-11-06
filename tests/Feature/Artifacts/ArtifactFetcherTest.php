<?php

declare(strict_types=1);

use App\Domain\Artifacts\ArtifactException;
use App\Domain\Artifacts\ArtifactFetcher;
use App\Domain\Artifacts\ArtifactIntegrityFailed;
use App\Domain\Artifacts\ArtifactSettings;
use App\Domain\Artifacts\ArtifactStore;
use App\Domain\Artifacts\HostResolver;
use App\Domain\Artifacts\PackageSource;
use App\Domain\Artifacts\PackageUrlGuard;
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
use Psr\Http\Message\RequestInterface;
use Tests\Support\FakeHostResolver;

const ARTIFACT_TOKEN = 'lic-7f3a9c2e5b8d1f4a';

beforeEach(function (): void {
    config(['wpup.artifacts.dir' => sys_get_temp_dir().'/wpup-artifacts-'.bin2hex(random_bytes(6)), 'wpup.artifacts.max_bytes' => 64 * 1024]);
    app(SecretRedactor::class)->reset();
    app()->instance(HostResolver::class, new FakeHostResolver(['internal.example.com' => ['192.168.1.10'], 'mixed.example.com' => ['93.184.216.34', '10.0.0.1'], 'nowhere.example.com' => []]));
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
 * @param  list<Response|Throwable|callable>  $responses
 *
 * @param-out MockHandler $mock
 */
function artifactFetcher(array $responses, ?MockHandler &$mock = null): ArtifactFetcher
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);

    return new ArtifactFetcher(new Client(['handler' => $stack]), app(ArtifactStore::class), app(PackageUrlGuard::class), app(ArtifactSettings::class));
}

function premiumSource(string $url = 'https://updates.example.com/download/'.ARTIFACT_TOKEN.'/plugin-x.zip?license_key='.ARTIFACT_TOKEN.'&site=shop'): PackageSource
{
    return PackageSource::create(ComponentType::Plugin, 'plugin-x', '2.1.0', $url, app(PackageUrlGuard::class));
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
        ->and($artifact->path)->toBe("{$root}/run-{$run->id}/plugin~plugin-x~2.1.0.zip")
        ->and(file_get_contents($artifact->path))->toBe($zip)
        ->and(fileperms($artifact->path) & 0777)->toBe(0600)
        ->and(fileperms(dirname($artifact->path)) & 0777)->toBe(0700)
        ->and($row->source_host)->toBe('updates.example.com')
        ->and($row->path)->toBe("run-{$run->id}/plugin~plugin-x~2.1.0.zip")
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
        ->toThrow(ArtifactException::class, "No s'ha pogut baixar el paquet de «plugin-x» 2.1.0 de updates.example.com.");
});

it('refuses non-HTTPS URLs, oversize packages and files that are not sane ZIPs', function (string $case): void {
    $run = Run::factory()->create();
    $big = str_repeat('a', 65 * 1024);

    $attempt = match ($case) {
        'http' => fn () => PackageSource::create(ComponentType::Plugin, 'plugin-x', '1.0', 'http://updates.example.com/x.zip', app(PackageUrlGuard::class)),
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

it('never reaches loopback, LAN, tailnet, link-local or odd ports, with the same message as a dead host', function (string $url, string $host): void {
    $run = Run::factory()->create();

    // An empty mock: any request would fail the test with OutOfBoundsException.
    expect(fn () => artifactFetcher([])->fetch($run->id, premiumSource($url)))
        ->toThrow(ArtifactException::class, "No s'ha pogut baixar el paquet de «plugin-x» 2.1.0 de {$host}.");
})->with([
    'loopback' => ['https://127.0.0.1/x.zip', '127.0.0.1'],
    'loopback v6' => ['https://[::1]/x.zip', '::1'],
    'unspecified' => ['https://0.0.0.0/x.zip', '0.0.0.0'],
    'lan' => ['https://10.1.2.3/x.zip', '10.1.2.3'],
    'tailnet' => ['https://100.101.102.103/x.zip', '100.101.102.103'],
    'metadata' => ['https://169.254.169.254/x.zip', '169.254.169.254'],
    'ula' => ['https://[fd7a:115c:a1e0::1]/x.zip', 'fd7a:115c:a1e0::1'],
    'link-local v6' => ['https://[fe80::1]/x.zip', 'fe80::1'],
    'v4-mapped' => ['https://[::ffff:127.0.0.1]/x.zip', '::ffff:127.0.0.1'],
    'port' => ['https://updates.example.com:8443/x.zip', 'updates.example.com'],
    'dns to lan' => ['https://internal.example.com/x.zip', 'internal.example.com'],
    'dns mixed' => ['https://mixed.example.com/x.zip', 'mixed.example.com'],
    'no dns' => ['https://nowhere.example.com/x.zip', 'nowhere.example.com'],
]);

it('checks every redirect hop the same way', function (): void {
    $run = Run::factory()->create();

    $fetcher = artifactFetcher([new Response(302, ['Location' => 'https://internal.example.com/x.zip'])], $mock);

    expect(fn () => $fetcher->fetch($run->id, premiumSource()))
        ->toThrow(ArtifactException::class, "No s'ha pogut baixar el paquet de «plugin-x» 2.1.0 de updates.example.com.")
        ->and($mock->count())->toBe(0);
});

it('pins the request to the address it checked (no DNS rebinding) and to HTTPS', function (): void {
    $run = Run::factory()->create();
    $zip = artifactZip();
    /** @var array<int, mixed> $seen */
    $seen = [];

    artifactFetcher([static function (RequestInterface $request, array $options) use ($zip, &$seen): Response {
        $seen = is_array($options['curl'] ?? null) ? $options['curl'] : [];

        return new Response(200, [], $zip);
    }])->fetch($run->id, premiumSource());

    expect($seen[CURLOPT_RESOLVE] ?? null)->toBe(['updates.example.com:443:'.FakeHostResolver::PUBLIC_IP])
        ->and($seen[CURLOPT_PROTOCOLS] ?? null)->toBe(CURLPROTO_HTTPS);
});

it('keeps the slug, version and site readable while masking the licence', function (): void {
    $licence = 'a1b2c3d4e5f6';
    $session = 'Zx9Qw8Er7Ty6Ui5Op4As3Df';
    premiumSource("https://updates.example.com/edd-sl/?edd_action=get_version&item_name=plugin-x&version=2.1.0&url=https://shop.example.com&license={$licence}&session_id={$session}");

    // What a step stores in run_steps goes through the redactor.
    $stored = app(SecretRedactor::class)->redact("plugin-x 2.1.0 a https://shop.example.com get_version licence {$licence} session {$session}");

    expect($stored)->toContain('plugin-x 2.1.0 a https://shop.example.com get_version')
        ->and(str_contains($stored, $licence) || str_contains($stored, $session))->toBeFalse();
});

it('gives artifacts unambiguous file names', function (): void {
    $run = Run::factory()->create();
    $guard = app(PackageUrlGuard::class);
    $a = PackageSource::create(ComponentType::Plugin, 'foo-1', '2.0', 'https://downloads.wordpress.org/plugin/a.zip', $guard);
    $b = PackageSource::create(ComponentType::Plugin, 'foo', '1-2.0', 'https://downloads.wordpress.org/plugin/b.zip', $guard);

    $first = artifactFetcher([new Response(200, [], artifactZip(['foo-1/a.php' => 'a']))])->fetch($run->id, $a);
    $second = artifactFetcher([new Response(200, [], artifactZip(['foo/b.php' => 'b']))])->fetch($run->id, $b);

    expect($first->path)->not->toBe($second->path)
        ->and(app(ArtifactStore::class)->verify($first))->toBe($first)
        ->and(app(ArtifactStore::class)->verify($second))->toBe($second);
});

it('turns filesystem failures into artifact errors instead of PHP warnings', function (): void {
    $run = Run::factory()->create();
    $store = app(ArtifactStore::class);
    $artifact = artifactFetcher([new Response(200, [], artifactZip())])->fetch($run->id, premiumSource());
    chmod(dirname($artifact->path), 0500);

    try {
        expect(fn () => $store->purge(RunArtifact::query()->sole(), now()->toImmutable()))
            ->toThrow(ArtifactException::class, "Magatzem d'artefactes: no s'ha pogut esborrar")
            ->and(RunArtifact::query()->sole()->purged_at)->toBeNull();
    } finally {
        chmod(dirname($artifact->path), 0700);
    }

    // A run directory that cannot be created.
    $other = Run::factory()->create();
    touch(artifactsRoot()."/run-{$other->id}");
    expect(fn () => $store->temporaryFile($other->id))->toThrow(ArtifactException::class, "Magatzem d'artefactes");
});
