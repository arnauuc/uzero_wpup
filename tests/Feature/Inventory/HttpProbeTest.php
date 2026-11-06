<?php

declare(strict_types=1);

use App\Domain\Drivers\Data\PhpRuntime;
use App\Domain\Inventory\HttpProbe;
use App\Domain\Inventory\SiteInventory;
use App\Enums\PhpVersionSource;
use App\Models\Run;
use App\Models\Site;
use App\Models\SiteSnapshot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDriver;

/*
 * The temporary HTTP probe (UZEROWPUP-30) with a FakeDriver and a faked HTTP client.
 */

/**
 * @return array{0: SiteSnapshot, 1: array{binary: string, cli_version: string, warnings: list<string>, sapi: ?string, error_log: ?string, extensions: list<string>, blocked_reason: ?string}}
 */
function probeInventory(FakeDriver $driver): array
{
    $run = Run::factory()->create(['type' => 'inventory', 'site_id' => Site::factory()->create(['url' => 'https://botiga.test'])->id]);
    $snapshot = app(SiteInventory::class)->take($run->site()->firstOrFail(), $driver, $run);
    /** @var array{binary: string, cli_version: string, warnings: list<string>, sapi: ?string, error_log: ?string, extensions: list<string>, blocked_reason: ?string} $php */
    $php = $snapshot->environment['php'] ?? [];

    return [$snapshot, $php];
}

it('takes the PHP version from the web and removes the probe', function (): void {
    Http::fake(['*' => Http::response(['php_version' => '8.3.14', 'sapi' => 'fpm-fcgi', 'extensions' => ['curl', 'json']])]);
    $driver = new FakeDriver;

    [$snapshot, $php] = probeInventory($driver);

    expect($snapshot->php_version)->toBe('8.3.14')
        ->and($snapshot->php_source)->toBe(PhpVersionSource::HttpProbe)
        ->and($php['extensions'])->toBe(['curl', 'json'])
        ->and($php['sapi'])->toBe('fpm-fcgi')
        ->and($php['blocked_reason'])->toBeNull()
        ->and($driver->webFiles)->toBe([]);
});

it('keeps the error_log the web reported, only when it is an absolute path', function (?string $reported, ?string $stored): void {
    Http::fake(['*' => Http::response(['php_version' => '8.3.14', 'error_log' => $reported])]);

    [, $php] = probeInventory(new FakeDriver);

    expect($php['error_log'])->toBe($stored);
})->with([
    'absolute' => ['/var/www/logs/php_error.log', '/var/www/logs/php_error.log'],
    'syslog' => ['syslog', null],
    'empty' => ['', null],
    'missing' => [null, null],
]);

it('sends a single-use token and stores only its hash in the probe', function (): void {
    Http::fake(['*' => Http::response(['php_version' => '8.3.14'])]);
    $driver = new FakeDriver;

    probeInventory($driver);

    $script = (string) array_values($driver->webFilesWritten)[0];
    $name = (string) array_key_first($driver->webFilesWritten);
    Http::assertSent(function (Request $request) use ($script, $name): bool {
        $header = $request->header('X-Wpup-Probe')[0] ?? null;
        $token = is_string($header) ? $header : '';

        return $request->url() === "https://botiga.test/{$name}"
            && strlen($token) === 64
            && ! str_contains($script, $token)
            && str_contains($script, hash('sha256', $token));
    });
    expect($name)->toMatch('/^wpup-probe-[0-9a-f]{24}\.php$/')
        ->and($script)->not->toContain('__WPUP_');
});

it('blocks the site when the web serves another PHP than WP-CLI', function (): void {
    Http::fake(['*' => Http::response(['php_version' => '7.4.33'])]);

    [$snapshot, $php] = probeInventory(new FakeDriver);

    expect($snapshot->php_version)->toBe('7.4.33')
        ->and($php['cli_version'])->toBe('8.3.14')
        ->and($php['blocked_reason'])->toContain('El web serveix PHP 7.4.33')
        ->and($php['warnings'])->toContain($php['blocked_reason']);
});

it('removes the probe and keeps the CLI PHP when the web does not answer', function (): void {
    Http::fake(static fn () => throw new ConnectionException('Connection refused'));
    $driver = new FakeDriver;

    [$snapshot, $php] = probeInventory($driver);

    expect($snapshot->php_source)->toBe(PhpVersionSource::Configured)
        ->and($php['blocked_reason'])->toBeNull()
        ->and(implode(' ', $php['warnings']))->toContain('no respon per HTTP')
        ->and($driver->webFiles)->toBe([]);
});

it('does not follow redirects and rejects invalid answers', function (int $status, array|string $body, string $error): void {
    Http::fake(['*' => Http::response($body, $status, $status === 301 ? ['Location' => 'https://other.test/'] : [])]);
    $driver = new FakeDriver;

    [, $php] = probeInventory($driver);

    expect(implode(' ', $php['warnings']))->toContain($error)
        ->and($driver->webFiles)->toBe([]);
})->with([
    'redirect' => [301, '', 'redirecció'],
    'not found' => [404, 'Not Found', 'HTTP 404'],
    'not a version' => [200, ['php_version' => '<script>'], 'no és vàlida'],
]);

it('never logs or returns the token', function (): void {
    Http::fake(static fn () => throw new ConnectionException('boom'));
    $driver = new FakeDriver;
    $result = app(HttpProbe::class)->run(Site::factory()->create(), $driver);

    expect($result->version)->toBeNull()
        ->and((string) $result->error)->not->toMatch('/[0-9a-f]{64}/');
});

it('removes a partial probe left by a failed upload', function (): void {
    Http::fake();
    $driver = new FakeDriver;
    $driver->failWebUpload = true;

    [, $php] = probeInventory($driver);

    expect($driver->webFiles)->toBe([])
        ->and(implode(' ', $php['warnings']))->toContain("no s'ha pogut pujar el probe");
    Http::assertNothingSent();
});

it('blocks the site when neither the handler nor the web tell its PHP', function (): void {
    Http::fake(['*' => Http::response('Forbidden', 403)]);
    $driver = new FakeDriver;
    $driver->phpRuntime = new PhpRuntime('8.2.20', 'php', PhpVersionSource::Cli, [PhpRuntime::UNVERIFIED_WARNING]);

    [, $php] = probeInventory($driver);

    expect($php['blocked_reason'])->toContain('No se sap quin PHP serveix el web');
});

it('drops the unverified warning once the web confirms the CLI PHP', function (): void {
    Http::fake(['*' => Http::response(['php_version' => '8.2.20'])]);
    $driver = new FakeDriver;
    $driver->phpRuntime = new PhpRuntime('8.2.20', 'php', PhpVersionSource::Cli, [PhpRuntime::UNVERIFIED_WARNING]);

    [, $php] = probeInventory($driver);

    expect($php['warnings'])->toBe([])
        ->and($php['blocked_reason'])->toBeNull();
});

it('refuses oversized or malformed versions', function (): void {
    Http::fake(['*' => Http::response(['php_version' => '8.3.14'.str_repeat('x', 300)])]);

    [$snapshot, $php] = probeInventory(new FakeDriver);

    expect($snapshot->php_source)->toBe(PhpVersionSource::Configured)
        ->and(implode(' ', $php['warnings']))->toContain('no és vàlida');
});

it('refuses an answer bigger than the limit without reading it', function (): void {
    config(['wpup.inventory.http_probe.max_response_bytes' => 100]);
    Http::fake(['*' => Http::response(['php_version' => '8.3.14', 'extensions' => array_fill(0, 50, 'curl')])]);

    [, $php] = probeInventory(new FakeDriver);

    expect(implode(' ', $php['warnings']))->toContain('no és vàlida');
});

it('does not touch the site in a dry run', function (): void {
    Http::fake();
    $driver = new FakeDriver;
    $run = Run::factory()->create(['type' => 'inventory', 'flags' => ['dry_run' => true]]);

    $snapshot = app(SiteInventory::class)->take($run->site()->firstOrFail(), $driver, $run, probeWeb: false);

    expect($driver->webFilesWritten)->toBe([])
        ->and(json_encode($snapshot->environment))->toContain('dry-run');
    Http::assertNothingSent();
});

it('switches WP-CLI to the PHP the web serves when it is installed', function (): void {
    Http::fake(['*' => Http::response(['php_version' => '7.4.33'])]);
    $driver = new FakeDriver;
    $driver->installedPhp = ['7.4' => '7.4.33'];

    [$snapshot, $php] = probeInventory($driver);

    expect($snapshot->php_version)->toBe('7.4.33')
        ->and($php['binary'])->toBe('/opt/plesk/php/7.4/bin/php')
        ->and($php['cli_version'])->toBe('7.4.33')
        ->and($php['blocked_reason'])->toBeNull()
        ->and($driver->phpRuntime()->version)->toBe('7.4.33');
});
