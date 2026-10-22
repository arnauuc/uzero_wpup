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

foreach (['wp74', 'wp83'] as $fixture) {
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
