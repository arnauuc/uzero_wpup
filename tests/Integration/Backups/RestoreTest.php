<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\RestorePoint;
use App\Domain\Backups\RestorePointGate;
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\RestoreSelection;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Drivers\DriverFactory;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Runs\RunEngine;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\AuditChannel;
use App\Enums\BackupKind;
use App\Enums\BackupTargetType;
use App\Enums\ComponentType;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\UserRole;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Run;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Fixtures;

/*
 * `backup:restore` (UZEROWPUP-46) end to end against a Docker test site (make
 * fixtures-up), never a real one. Each test uses its own root of the
 * site-local store, removed at the end, and leaves the site as the fixture
 * had it.
 */

const RESTORE_TEST_FIXTURE = 'wp83';

if (! Fixtures::up(RESTORE_TEST_FIXTURE)) {
    it('restores a site to a restore point')->skip('Llocs de prova aturats: make fixtures-up');

    return;
}

/**
 * The fixture site with a store root of its own.
 *
 * @return array{Site, SiteDriver, string}
 */
function restoreFixture(): array
{
    Redis::connection('locks')->command('flushdb');
    $root = 'restore-points/it-restore-'.bin2hex(random_bytes(6));
    $site = Fixtures::site(RESTORE_TEST_FIXTURE);
    $site->update(['url' => 'http://'.RESTORE_TEST_FIXTURE.':8083']);
    app(SiteLocalDestination::class)->attach($site);
    app(BackupTargetRegistry::class)->register(BackupTargetType::SiteLocal, static fn (BackupTargetRecord $r, Site $s, AuditActor $a): BackupTarget => new SiteLocalTarget(
        app(DriverFactory::class)->forSite($s, $a), app(SecretRedactor::class), app(HttpFactory::class), app(SiteLocalSettings::class)->withRoot($root), $s->url,
    ));

    // The run leaves the maintenance mode as it found it: start without a .maintenance
    // file that another test (or an interrupted run) may have left.
    Fixtures::run(RESTORE_TEST_FIXTURE, 'rm -f '.escapeshellarg("{$site->wp_path}/.maintenance"));

    return [$site, app(DriverFactory::class)->forSite($site, AuditActor::system('tests')), $root];
}

/** Restores files and database of $point as an operator who confirmed the data loss (a shop). */
function restoreFixtureRun(Site $site, RestorePoint $point): Run
{
    $user = User::factory()->create(['role' => UserRole::Operator]);
    $request = app(RestoreSelection::class)->select($site, $point->directory, [], [BackupKind::Files, BackupKind::Database], acceptDataLoss: true)->with(true, false);

    return app(RunEngine::class)->execute(Run::factory()->create([
        'type' => RunType::Restore, 'site_id' => $site->id, 'user_id' => $user->id, 'actor' => $user->name, 'channel' => AuditChannel::Cli,
        'flags' => $request->flags(),
    ]));
}

function restoreFixtureBlogname(SiteDriver $driver): string
{
    return trim($driver->wp(['option', 'get', 'blogname', '--skip-plugins', '--skip-themes'])->stdout);
}

it('restores a site to a restore point: same plugin file, same database, and a verified point of the state before', function (): void {
    [$site, $driver, $root] = restoreFixture();
    $fixture = RESTORE_TEST_FIXTURE;
    $plugin = escapeshellarg("{$site->wp_path}/wp-content/plugins/classic-editor/classic-editor.php");
    $extra = escapeshellarg("{$site->wp_path}/wp-content/plugins/classic-editor/wpup-it-extra.php");
    $sha = static fn (): string => explode(' ', Fixtures::run($fixture, "sha256sum {$plugin}"))[0];
    [$originalSha, $originalName] = [$sha(), restoreFixtureBlogname($driver)];
    // Outside the docroot: the restore mirrors it with --delete.
    Fixtures::run($fixture, "cp -p {$plugin} \"\$HOME/wpup-it-restore.bak\"");

    try {
        $point = app(RestorePointService::class)->create($site, $driver, null, AuditActor::system('tests'));

        Fixtures::run($fixture, "printf '\\n// changed by the restore test\\n' >> {$plugin} && printf '<?php\\n' > {$extra}");
        $driver->wp(['option', 'update', 'blogname', 'canviat pel test de restauració']);
        expect($sha())->not->toBe($originalSha)->and(restoreFixtureBlogname($driver))->not->toBe($originalName);

        $run = restoreFixtureRun($site, $point);

        expect($run->status)->toBe(RunStatus::Succeeded, (string) $run->failureReason())
            ->and($sha())->toBe($originalSha)
            ->and(Fixtures::run($fixture, "test -e {$extra} && echo present || echo gone"))->toBe("gone\n")
            ->and(restoreFixtureBlogname($driver))->toBe($originalName)
            ->and(app(RestorePointGate::class)->isVerified($run))->toBeTrue()
            ->and($driver->wp(['maintenance-mode', 'is-active'])->successful())->toBeFalse()
            // Nothing of the restore left in the work directory, outside the store.
            ->and(Fixtures::run($fixture, 'ls "$HOME/.wpup" | grep -cE "^restore-[0-9a-f]{16}" || true'))->toBe("0\n");
    } finally {
        // Whatever happened, the fixture keeps its state (the database is restored by the run or untouched).
        Fixtures::run($fixture, "mv -f \"\$HOME/wpup-it-restore.bak\" {$plugin}; rm -f {$extra}");
        if (restoreFixtureBlogname($driver) !== $originalName) {
            $driver->wp(['option', 'update', 'blogname', $originalName, '--skip-plugins', '--skip-themes']);
        }
        $driver->setMaintenanceMode(false);
        Fixtures::run($fixture, 'rm -rf "$HOME/.wpup/"'.escapeshellarg($root));
    }
});

it('restores a site that a plugin breaks with a fatal error, making the point of the broken state first', function (): void {
    [$site, $driver, $root] = restoreFixture();
    $fixture = RESTORE_TEST_FIXTURE;
    $dir = "{$site->wp_path}/wp-content/plugins/wpup-it-fatal";
    $file = escapeshellarg("{$dir}/wpup-it-fatal.php");
    $originalName = restoreFixtureBlogname($driver);

    try {
        $point = app(RestorePointService::class)->create($site, $driver, null, AuditActor::system('tests'));

        // Activated while harmless, then broken: every normal WP-CLI command now dies.
        Fixtures::run($fixture, 'mkdir -p '.escapeshellarg($dir)." && printf '<?php\\n/* Plugin Name: wpup-it-fatal */\\n' > {$file}");
        $driver->wp(['plugin', 'activate', 'wpup-it-fatal']);
        $driver->wp(['option', 'update', 'blogname', 'trencat pel test']);
        Fixtures::run($fixture, "printf '<?php\\n/* Plugin Name: wpup-it-fatal */\\nthrow new Error(\"wpup-it fatal\");\\n' > {$file}");
        expect($driver->wp(['plugin', 'list'])->successful())->toBeFalse();

        $run = restoreFixtureRun($site, $point);

        expect($run->status)->toBe(RunStatus::Succeeded, (string) $run->failureReason())
            ->and(app(RestorePointGate::class)->isVerified($run))->toBeTrue()
            ->and(Fixtures::run($fixture, 'test -e '.escapeshellarg($dir).' && echo present || echo gone'))->toBe("gone\n")
            ->and(restoreFixtureBlogname($driver))->toBe($originalName)
            ->and($driver->installedComponents()->find(ComponentType::Plugin, 'wpup-it-fatal'))->toBeNull()
            ->and($driver->wp(['maintenance-mode', 'is-active'])->successful())->toBeFalse();
    } finally {
        Fixtures::run($fixture, 'rm -rf '.escapeshellarg($dir));
        $driver->wp(['plugin', 'deactivate', 'wpup-it-fatal', '--skip-plugins', '--skip-themes']);
        if (restoreFixtureBlogname($driver) !== $originalName) {
            $driver->wp(['option', 'update', 'blogname', $originalName, '--skip-plugins', '--skip-themes']);
        }
        $driver->setMaintenanceMode(false);
        Fixtures::run($fixture, 'rm -rf "$HOME/.wpup/"'.escapeshellarg($root));
    }
});
