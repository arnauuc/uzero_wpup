<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Drivers\Ssh\SshConnection;
use App\Domain\Drivers\Ssh\SshDriver;
use App\Domain\Drivers\Ssh\SshDriverFactory;
use App\Enums\CheckResult;
use App\Enums\ComponentType;
use App\Enums\HostingPanel;
use App\Enums\PhpVersionSource;
use App\Models\SiteSnapshot;
use Tests\Contracts\SiteDriverContract;
use Tests\Support\Fixtures;

/*
 * SshDriver against the Docker test sites (make fixtures-up). Never against a
 * real site. Skipped, with a visible reason, when the fixtures are not running.
 */

function sshFixture(string $name = 'wp83', ?string $user = null, ?string $hostKey = null, bool $pin = true): SshDriver
{
    return app(SshDriverFactory::class)->forSite(Fixtures::site($name, $user, $hostKey, $pin), AuditActor::system('tests'));
}

foreach (['wp74', 'wp83', 'wp82'] as $fixture) {
    if (Fixtures::up($fixture)) {
        SiteDriverContract::register(fn (): SiteDriver => sshFixture($fixture), "SshDriver ({$fixture})");
    } else {
        it("passes the SiteDriver contract on {$fixture}")->skip('Llocs de prova aturats: make fixtures-up');
    }
}

if (! Fixtures::up('wp74') || ! Fixtures::up('wp83')) {
    it('works against the test sites')->skip('Llocs de prova aturats: make fixtures-up');

    return;
}

describe('SshDriver', function (): void {
    it('reports the domain PHP and the installed components', function (): void {
        $installed = sshFixture('wp74')->installedComponents();

        expect($installed->phpVersion)->toStartWith('7.4.')
            ->and($installed->coreVersion)->toBe('6.6.2')
            ->and($installed->find(ComponentType::Woocommerce, 'woocommerce')?->version)->toBe('9.3.3')
            ->and($installed->find(ComponentType::Plugin, 'wpup-php-guard')?->active)->toBeFalse()
            ->and($installed->activeTheme)->not->toBeNull()
            ->and($installed->dbServerVersion)->toContain('MariaDB');
    });

    it('refuses a host key that does not match the pinned one', function (): void {
        $other = SshConnection::fetchHostKey('wp74', 22, 5);
        $driver = sshFixture('wp83', hostKey: $other);

        expect($driver->probe()->checks[0]->result)->toBe(CheckResult::Error)
            ->and($driver->probe()->checks[0]->detail)->toContain('no coincideix')
            ->and(fn () => $driver->freeDiskBytes())->toThrow(ConnectionFailed::class);
    });

    it('refuses to connect without a pinned host key', function (): void {
        expect(fn () => sshFixture('wp83', pin: false)->freeDiskBytes())->toThrow(ConnectionFailed::class, 'no està fixada');
    });

    it('explains an account without a usable shell', function (): void {
        $checks = sshFixture('wp83', user: 'sub_wp83_nosh')->probe()->checks;
        $last = $checks[count($checks) - 1];

        expect($last->name)->toBe('shell')
            ->and($last->result)->toBe(CheckResult::Error)
            ->and($last->detail)->toContain('/bin/false');
    });

    it('passes hostile arguments as data, never as shell code', function (): void {
        $driver = sshFixture('wp83');
        $marker = '/tmp/wpup-pwned-'.bin2hex(random_bytes(4));

        $driver->wp(['option', 'get', "x'; touch {$marker}; echo '"]);
        $driver->wp(['option', 'get', "\$(touch {$marker})"]);

        expect($driver->wp(['eval', "echo file_exists('{$marker}') ? 'yes' : 'no';"])->stdout)->toBe('no')
            ->and(fn () => $driver->remove('../wp-config.php'))->toThrow(DriverException::class)
            ->and(fn () => $driver->remove('a;rm -rf ~'))->toThrow(DriverException::class);
    });

    it('enforces the per-command timeout', function (): void {
        expect(fn () => sshFixture('wp83')->wp(['eval', 'sleep(5);'], 1))->toThrow(RemoteCommandFailed::class, 'temps màxim');
    });

    it('never leaves key material on the tool server after a transfer', function (): void {
        $before = glob(sys_get_temp_dir().'/wpup-ssh-*') ?: [];
        $driver = sshFixture('wp83');
        $file = $driver->dumpDatabase(SiteDriverContract::remoteName('db.sql.gz'));
        $local = SiteDriverContract::localTemp();

        try {
            $driver->download($file, $local);
        } finally {
            @unlink($local);
            $driver->remove($file->path);
        }

        expect(glob(sys_get_temp_dir().'/wpup-ssh-*') ?: [])->toBe($before);
    });
});

/*
 * Real PHP detection (UZEROWPUP-29). wp83 has Plesk's PHP layout (PLESK_PHP_LAYOUT),
 * wp74 does not.
 */
it('uses the PHP of the domain handler on Plesk', function (): void {
    $runtime = sshFixture('wp83')->phpRuntime();

    // wp83 also has a stale, older pool under 7.4 (PLESK_STALE_POOL_VERSION).
    expect($runtime->source)->toBe(PhpVersionSource::PleskHandler)
        ->and($runtime->binary)->toBe('/opt/plesk/php/8.3/bin/php')
        ->and($runtime->minor())->toBe('8.3')
        ->and(implode(' ', $runtime->warnings))->toContain('es fa servir el més recent');
});

it('prefers the domain handler over a different configured binary, with a warning', function (): void {
    $site = Fixtures::site('wp83');
    $site->update(['php_binary' => '/usr/local/bin/php']);
    $runtime = app(SshDriverFactory::class)->forSite($site, AuditActor::system('tests'))->phpRuntime();

    expect($runtime->source)->toBe(PhpVersionSource::PleskHandler)
        ->and($runtime->binary)->toBe('/opt/plesk/php/8.3/bin/php')
        ->and(implode(' ', $runtime->warnings))->toContain('/usr/local/bin/php');
});

it('falls back to the configured binary when the handler cannot be read', function (): void {
    $site = Fixtures::site('wp74');
    $site->update(['php_binary' => '/usr/local/bin/php']);
    $runtime = app(SshDriverFactory::class)->forSite($site, AuditActor::system('tests'))->phpRuntime();

    expect($runtime->source)->toBe(PhpVersionSource::Configured)
        ->and($runtime->minor())->toBe('7.4')
        ->and(implode(' ', $runtime->warnings))->toContain('No s\'ha trobat el pool');
});

it('marks the CLI PHP as unverified when nothing better is known', function (): void {
    $runtime = sshFixture('wp74')->phpRuntime();

    expect($runtime->source)->toBe(PhpVersionSource::Cli)
        ->and($runtime->binary)->toBe('php')
        ->and(implode(' ', $runtime->warnings))->toContain('pot no ser el que serveix el web');
});

it('does not look for a Plesk handler on other panels', function (): void {
    $site = Fixtures::site('wp83');
    $site->hostingAccount()->firstOrFail()->hostingServer()->firstOrFail()->update(['panel' => HostingPanel::Other]);
    $runtime = app(SshDriverFactory::class)->forSite($site->fresh() ?? $site, AuditActor::system('tests'))->phpRuntime();

    expect($runtime->source)->toBe(PhpVersionSource::Cli);
});

it('reads the version from the last line when php.ini prints startup warnings', function (): void {
    // A wrapper that, like a noisy php.ini, writes a warning to stdout first.
    Fixtures::run('wp74', 'printf \'#!/bin/sh\necho "PHP Warning:  Module imagick already loaded in Unknown on line 0"\nexec php "$@"\n\' > "$HOME/noisy-php" && chmod +x "$HOME/noisy-php"');
    $site = Fixtures::site('wp74');
    $site->update(['php_binary' => '/var/www/vhosts/wp74.fixtures.test/noisy-php']);

    try {
        $runtime = app(SshDriverFactory::class)->forSite($site, AuditActor::system('tests'))->phpRuntime();

        expect($runtime->source)->toBe(PhpVersionSource::Configured)
            ->and($runtime->version)->toMatch('/^7\.4\.\d+$/');
    } finally {
        Fixtures::run('wp74', 'rm -f "$HOME/noisy-php"');
    }
});

it('falls back to the account PHP when the configured binary does not run', function (): void {
    $site = Fixtures::site('wp74');
    $site->update(['php_binary' => '/nonexistent/php']);
    $runtime = app(SshDriverFactory::class)->forSite($site, AuditActor::system('tests'))->phpRuntime();

    expect($runtime->source)->toBe(PhpVersionSource::Cli)
        ->and(implode(' ', $runtime->warnings))->toContain('/nonexistent/php');
});

it('prefers the PHP the web was last seen serving over the pools', function (): void {
    $site = Fixtures::site('wp83');
    SiteSnapshot::factory()->for($site)->create(['php_version' => '8.3.99', 'php_source' => PhpVersionSource::HttpProbe, 'taken_at' => now()]);
    $runtime = app(SshDriverFactory::class)->forSite($site, AuditActor::system('tests'))->phpRuntime();

    expect($runtime->binary)->toBe('/opt/plesk/php/8.3/bin/php')
        ->and($runtime->warnings)->toBe([]);
});

it('adopts the installed PHP of the web, and refuses one that is not installed', function (): void {
    $driver = sshFixture('wp83');

    expect($driver->adoptWebPhp('7.4'))->toBeNull()
        ->and($driver->adoptWebPhp('8.3')?->binary)->toBe('/opt/plesk/php/8.3/bin/php');
});
