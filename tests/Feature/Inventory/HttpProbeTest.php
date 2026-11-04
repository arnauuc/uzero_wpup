<?php

declare(strict_types=1);

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
 * @return array{0: SiteSnapshot, 1: array{binary: string, cli_version: string, warnings: list<string>, sapi: ?string, extensions: list<string>, blocked_reason: ?string}}
 */
function probeInventory(FakeDriver $driver): array
{
    $run = Run::factory()->create(['type' => 'inventory', 'site_id' => Site::factory()->create(['url' => 'https://botiga.test'])->id]);
    $snapshot = app(SiteInventory::class)->take($run->site()->firstOrFail(), $driver, $run);
    /** @var array{binary: string, cli_version: string, warnings: list<string>, sapi: ?string, extensions: list<string>, blocked_reason: ?string} $php */
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
