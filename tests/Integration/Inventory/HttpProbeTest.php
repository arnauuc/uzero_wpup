<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\Ssh\SshDriver;
use App\Domain\Drivers\Ssh\SshDriverFactory;
use App\Domain\Inventory\HttpProbe;
use App\Domain\Inventory\SiteInventory;
use App\Enums\PhpVersionSource;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures;

/*
 * The HTTP probe (UZEROWPUP-30) against the Docker test sites (make fixtures-up).
 */

if (! Fixtures::up('wp74') || ! Fixtures::up('wp83')) {
    it('probes the test sites over HTTP')->skip('Llocs de prova aturats: make fixtures-up');

    return;
}

/**
 * A fixture site reachable from the app container over HTTP.
 *
 * @return array{Site, SshDriver}
 */
function probeFixture(string $name, int $port): array
{
    $site = Fixtures::site($name);
    $site->update(['url' => "http://{$name}:{$port}"]);

    return [$site, app(SshDriverFactory::class)->forSite($site, AuditActor::system('tests'))];
}

function probeScript(string $token, int $expiresAt): string
{
    return strtr((string) file_get_contents(base_path('resources/probe/probe.php')), [
        '__WPUP_TOKEN_SHA256__' => hash('sha256', $token),
        '__WPUP_EXPIRES_AT__' => (string) $expiresAt,
    ]);
}

function probeLeftovers(string $name): string
{
    return trim(Fixtures::run($name, "ls /var/www/vhosts/{$name}.fixtures.test/httpdocs | grep '^wpup-probe-' || true"));
}

it('reports the PHP the web serves and leaves nothing behind', function (): void {
    [$site, $driver] = probeFixture('wp74', 8081);

    $result = app(HttpProbe::class)->run($site, $driver);

    expect($result->version)->toMatch('/^7\.4\.\d+$/')
        ->and($result->sapi)->toBe('apache2handler')
        ->and($result->extensions)->toContain('mysqli')
        ->and($result->removed)->toBeTrue()
        ->and(probeLeftovers('wp74'))->toBe('');
});

it('answers a valid token only once, and a wrong one never', function (): void {
    [$site, $driver] = probeFixture('wp83', 8083);
    $url = 'http://wp83:8083/wpup-probe-contracttest.php';

    try {
        $driver->putWebFile('wpup-probe-contracttest.php', probeScript('right-token', time() + 60));

        $wrong = Http::withHeaders(['X-Wpup-Probe' => 'wrong-token'])->get($url);
        $none = Http::get($url);
        $right = Http::withHeaders(['X-Wpup-Probe' => 'right-token'])->get($url);
        $again = Http::withHeaders(['X-Wpup-Probe' => 'right-token'])->get($url);

        expect($wrong->status())->toBe(404)
            ->and($wrong->body())->toBe('Not Found')
            ->and($none->status())->toBe(404)
            ->and($right->json('php_version'))->toMatch('/^8\.3\.\d+$/')
            ->and($again->status())->toBe(404)
            ->and(probeLeftovers('wp83'))->toBe('');
    } finally {
        $driver->removeWebFile('wpup-probe-contracttest.php');
    }
});

it('refuses to answer and deletes itself after its deadline', function (): void {
    [, $driver] = probeFixture('wp83', 8083);
    $driver->putWebFile('wpup-probe-expired.php', probeScript('right-token', time() - 1));

    $response = Http::withHeaders(['X-Wpup-Probe' => 'right-token'])->get('http://wp83:8083/wpup-probe-expired.php');

    expect($response->status())->toBe(404)
        ->and(probeLeftovers('wp83'))->toBe('');
});

it('never overwrites a file of the site', function (): void {
    [, $driver] = probeFixture('wp83', 8083);
    $driver->putWebFile('wpup-probe-once.php', '<?php echo 1;');

    try {
        expect(fn () => $driver->putWebFile('wpup-probe-once.php', '<?php echo 2;'))->toThrow(DriverException::class);
    } finally {
        $driver->removeWebFile('wpup-probe-once.php');
    }
});

it('records the web PHP in the inventory of a real site', function (): void {
    [$site, $driver] = probeFixture('wp83', 8083);

    $snapshot = app(SiteInventory::class)->take($site, $driver);
    $php = $snapshot->environment['php'] ?? [];

    expect($snapshot->php_source)->toBe(PhpVersionSource::HttpProbe)
        ->and($snapshot->php_version)->toMatch('/^8\.3\.\d+$/')
        ->and(is_array($php) ? $php['blocked_reason'] : 'missing')->toBeNull();
});
