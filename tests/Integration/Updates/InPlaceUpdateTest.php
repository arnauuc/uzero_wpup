<?php

declare(strict_types=1);

use App\Domain\Artifacts\ArtifactStore;
use App\Domain\Artifacts\PackageSource;
use App\Domain\Artifacts\PackageUrlGuard;
use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupStep;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\RestorePointGate;
use App\Domain\Backups\RestorePointGateStep;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Drivers\DriverFactory;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Maintenance\MaintenanceCheckStep;
use App\Domain\Maintenance\MaintenanceForceOnStep;
use App\Domain\Maintenance\MaintenanceOffStep;
use App\Domain\Maintenance\MaintenanceOnStep;
use App\Domain\Maintenance\MaintenanceRestoreStep;
use App\Domain\Planning\PlanStep;
use App\Domain\Rollback\DatabaseRollbackStep;
use App\Domain\Rollback\FilesRollbackStep;
use App\Domain\Runs\RunEngine;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Updates\PhpLogBaselineStep;
use App\Domain\Updates\SmokeCheckStep;
use App\Enums\AuditChannel;
use App\Enums\BackupTargetType;
use App\Enums\ChangeResult;
use App\Enums\ComponentType;
use App\Enums\RunStatus;
use App\Enums\RunStepKind;
use App\Enums\RunType;
use App\Enums\SkipReason;
use App\Enums\UpdateStrategy;
use App\Enums\UserRole;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\PackageVersion;
use App\Models\Run;
use App\Models\Site;
use App\Models\SiteProfile;
use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\Contracts\SiteDriverContract;
use Tests\Support\Fixtures;

/*
 * The update run end to end (UZEROWPUP-60, 63) against the Docker test sites
 * (make fixtures-up), never real ones: the pipeline `wpup update` runs, as the
 * application builds it (inventory with the web probe, plan with the declared
 * requirements and PHPCompatibility, a real restore point, maintenance mode,
 * the update, the smoke check and, when something fails, the run's own
 * rollback of the files and, without new orders, of the database).
 *
 * Nothing depends on WordPress.org: the site is offered an update of a tiny
 * test plugin through its own update_plugins transient (as a premium plugin
 * would be), whose package the run already holds as its artifact (the
 * download has its own tests), and the version is past the quarantine. The
 * core is excluded by the site's profile. Every fatal carries a token no
 * earlier run wrote (the fixture's PHP log is kept between runs). The store
 * root is the test's own; the docroot, the transients, the plugin and the
 * orders the test writes are put back or removed. Afterwards,
 * `snapshot.sh restore wp74 wp83`.
 */

const IN_PLACE_FIXTURE = 'wp83';

const IN_PLACE_PLUGIN = 'wpup-contract-plugin';

const IN_PLACE_PACKAGE_URL = 'https://updates.example.com/wpup-contract-plugin.zip';

/** HTTP port of each fixture site, as the tool reaches it. */
const IN_PLACE_PORTS = ['wp74' => 8081, 'wp83' => 8083];

if (! Fixtures::up(IN_PLACE_FIXTURE) || ! Fixtures::up('wp74')) {
    it('updates a test site in place and rolls it back')->skip('Llocs de prova aturats: make fixtures-up');

    return;
}

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    config(['wpup.artifacts.dir' => sys_get_temp_dir().'/wpup-artifacts-'.bin2hex(random_bytes(6))]);
    // The tool never asks WordPress.org here: nothing offered comes from it.
    Http::fake(['api.wordpress.org/*' => Http::response([], 404), 'downloads.wordpress.org/*' => Http::response('', 404)]);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg(artifactsRoot()));
});

/**
 * A zip of the test plugin whose header says $version, with $code after it.
 */
function fixtureUpdatePluginZip(string $version, string $code = ''): string
{
    $path = sys_get_temp_dir().'/wpup-e2e-plugin-'.bin2hex(random_bytes(4)).'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString(IN_PLACE_PLUGIN.'/'.IN_PLACE_PLUGIN.'.php', "<?php\n/**\n * Plugin Name: Uzero wpup contract plugin\n * Version: {$version}\n * Description: Test-only plugin of the update integration tests.\n */\n{$code}\n");
    $zip->close();

    return $path;
}

/**
 * Code of a new version that breaks every web request (never WP-CLI, so the
 * update and the post-update step pass): it marks the database with $token
 * and, when $order, takes an order as a customer would, then dies with a
 * fatal that names $token.
 */
function fixtureUpdateBreakingCode(string $token, bool $order): string
{
    $placeOrder = $order ? '$o = wc_create_order(); $o->set_total(12.5); $o->set_status("processing"); $o->save();' : '';

    return "if (PHP_SAPI !== 'cli') {\n    add_action('init', static function () {\n        {$placeOrder}\n"
        ."        update_option('wpup_e2e_marker', '{$token}', false);\n        throw new RuntimeException('wpup-e2e-{$token}');\n    }, 99);\n}";
}

/**
 * Offers the update through the site's own transients, as WordPress would
 * store it: the "checked" lists match what is installed, so listing the
 * plugins does not rebuild them. No theme update is offered.
 *
 * @param  array<string, mixed>  $offer  extra fields of the entry (requires_php…)
 */
function fixtureUpdateOffer(SiteDriver $driver, string $version, array $offer): void
{
    $entry = json_encode([
        'slug' => IN_PLACE_PLUGIN, 'plugin' => IN_PLACE_PLUGIN.'/'.IN_PLACE_PLUGIN.'.php', 'new_version' => $version,
        'url' => 'https://updates.example.com/', 'package' => IN_PLACE_PACKAGE_URL, 'requires' => '6.0', 'requires_php' => '7.4', 'requires_plugins' => [],
        ...$offer,
    ], JSON_THROW_ON_ERROR);
    $file = var_export(IN_PLACE_PLUGIN.'/'.IN_PLACE_PLUGIN.'.php', true);
    $result = $driver->wp(['eval', 'if (!function_exists("get_plugins")) { require_once ABSPATH . "wp-admin/includes/plugin.php"; }'
        .' $checked = array(); foreach (get_plugins() as $f => $d) { $checked[$f] = $d["Version"]; }'
        .' $themes = array(); foreach (wp_get_themes() as $s => $t) { $themes[$s] = $t->get("Version"); }'
        .' set_site_transient("update_plugins", (object) array("last_checked" => time(), "checked" => $checked, "response" => array('.$file.' => json_decode('.var_export($entry, true).')), "no_update" => array(), "translations" => array()));'
        .' set_site_transient("update_themes", (object) array("last_checked" => time(), "checked" => $themes, "response" => array(), "no_update" => array(), "translations" => array()));'
        .' echo "ok";']);
    expect(trim($result->stdout))->toBe('ok', $result->stderr);
}

/**
 * Every file of the docroot but the uploads, with its SHA-256.
 *
 * @return list<string>
 */
function fixtureUpdateTree(string $fixture, Site $site): array
{
    $out = Fixtures::run($fixture, 'cd '.escapeshellarg($site->wp_path).' && find . -path ./wp-content/uploads -prune -o -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum');

    return array_values(array_filter(explode("\n", $out)));
}

/**
 * The lines only one of the two trees has (a changed file shows twice).
 *
 * @param  list<string>  $before
 * @param  list<string>  $after
 * @return list<string>
 */
function fixtureUpdateTreeDiff(array $before, array $after): array
{
    return [...array_diff($before, $after), ...array_diff($after, $before)];
}

/**
 * Runs `wpup update` on $fixture after installing version 1.0.0 of the test
 * plugin (active when $activate) and offering 1.1.0, whose package holds
 * $packageZip. Leaves the site as it was and checks that nothing of the run
 * stays on it.
 *
 * @param  Closure(Run, SiteDriver, Site): void  $check
 * @param  array<string, mixed>  $offer  extra fields of the offered update (requires_php…)
 * @param  (Closure(Site, SiteDriver): void)|null  $before  called right before the run, once the site is ready
 */
function fixtureUpdate(string $fixture, string $packageZip, Closure $check, array $offer = [], bool $activate = false, ?Closure $before = null): void
{
    $storeRoot = 'restore-points/it-update-'.bin2hex(random_bytes(6));
    $site = Fixtures::site($fixture);
    $site->update(['url' => "http://{$fixture}:".IN_PLACE_PORTS[$fixture]]);
    // The core is not part of these runs, whatever WordPress.org offers the fixture today.
    SiteProfile::factory()->create(['site_id' => $site->id, 'excluded_components' => ['core:wordpress']]);
    app(SiteLocalDestination::class)->attach($site);
    app(BackupTargetRegistry::class)->register(BackupTargetType::SiteLocal, static fn (BackupTargetRecord $r, Site $s, AuditActor $a): BackupTarget => new SiteLocalTarget(
        app(DriverFactory::class)->forSite($s, $a), app(SecretRedactor::class), app(HttpFactory::class), app(SiteLocalSettings::class)->withRoot($storeRoot), $s->url,
    ));
    // Seen long ago: past the quarantine (ADR-0005), which has its own tests.
    PackageVersion::query()->create(['component_type' => ComponentType::Plugin, 'slug' => IN_PLACE_PLUGIN, 'source' => 'host:updates.example.com', 'version' => '1.1.0',
        'first_seen_at' => now()->subDays(30), 'last_seen_at' => now()->subDays(30)]);
    $driver = app(DriverFactory::class)->forSite($site, AuditActor::system('tests'));
    $archive = $driver->archiveFiles(SiteDriverContract::remoteName('files.tar.gz'), ['wp-content/uploads']);
    $old = fixtureUpdatePluginZip('1.0.0');
    $user = User::factory()->create(['role' => UserRole::Operator]);

    try {
        $driver->applyUpdate(SiteDriverContract::package($old, '1.0.0'));
        if ($activate) {
            expect($driver->wp(['plugin', 'activate', IN_PLACE_PLUGIN])->successful())->toBeTrue();
        }
        fixtureUpdateOffer($driver, '1.1.0', $offer);

        $run = Run::factory()->create(['type' => RunType::Update, 'site_id' => $site->id, 'user_id' => $user->id,
            'actor' => $user->name, 'channel' => AuditChannel::Cli, 'strategy' => UpdateStrategy::InPlace]);
        // The artifact the plan step would have downloaded for this run.
        $store = app(ArtifactStore::class);
        $temporary = $store->temporaryFile($run->id);
        copy($packageZip, $temporary);
        $source = PackageSource::create(ComponentType::Plugin, IN_PLACE_PLUGIN, '1.1.0', IN_PLACE_PACKAGE_URL, app(PackageUrlGuard::class));
        $store->commit($run->id, $source, $temporary, (string) hash_file('sha256', $packageZip), (int) filesize($packageZip));

        if ($before !== null) {
            $before($site, $driver);
        }
        $run = app(RunEngine::class)->execute($run);

        $check($run, $driver, $site);
    } finally {
        $driver->wp(['plugin', 'deactivate', IN_PLACE_PLUGIN, '--skip-plugins', '--skip-themes']);
        $driver->wp(['transient', 'delete', 'update_plugins', '--network']);
        $driver->wp(['transient', 'delete', 'update_themes', '--network']);
        $driver->restoreFiles($archive);
        $driver->remove($archive->path);
        Fixtures::run($fixture, 'rm -rf "$HOME/.wpup/"'.escapeshellarg($storeRoot).' '.escapeshellarg("{$site->wp_path}/.maintenance"));
        @unlink($old);
        @unlink($packageZip);
    }

    // Nothing of the run is left on the site: work files, packages, the plugin, the mode.
    expect(Fixtures::run($fixture, 'ls -a "$HOME/.wpup" 2>/dev/null | grep -cE "^(restore-[0-9a-f]{16}|wpup-)" || true'))->toBe("0\n")
        ->and(SiteDriverContract::packageTemporaries($driver))->toBe([])
        ->and(Fixtures::run($fixture, 'ls -d '.escapeshellarg("{$site->wp_path}/wp-content/plugins/".IN_PLACE_PLUGIN).' '.escapeshellarg("{$site->wp_path}/.maintenance").' 2>/dev/null || true'))->toBe('');
}

/**
 * The forward steps of an update that applies one plugin, in order.
 *
 * @return list<string>
 */
function fixtureUpdateSteps(): array
{
    return [
        InventoryStep::NAME, PlanStep::NAME, MaintenanceCheckStep::NAME, BackupStep::NAME, PhpLogBaselineStep::NAME, RestorePointGateStep::NAME, MaintenanceOnStep::NAME,
        'update:plugin:'.IN_PLACE_PLUGIN, 'post-update', MaintenanceOffStep::NAME, SmokeCheckStep::NAME, MaintenanceRestoreStep::NAME,
    ];
}

function fixtureUpdateVersion(SiteDriver $driver): ?string
{
    return $driver->installedComponents()->find(ComponentType::Plugin, IN_PLACE_PLUGIN)?->version;
}

function fixtureUpdateMarker(SiteDriver $driver): string
{
    return trim($driver->wp(['option', 'get', 'wpup_e2e_marker', '--skip-plugins', '--skip-themes'])->stdout);
}

it('updates a plugin of wp83 in place behind a verified restore point and puts the mode back', function (): void {
    fixtureUpdate(IN_PLACE_FIXTURE, fixtureUpdatePluginZip('1.1.0'), static function (Run $run, SiteDriver $driver): void {
        $change = $run->changes()->where('slug', IN_PLACE_PLUGIN)->sole();

        expect($run->status)->toBe(RunStatus::Succeeded, (string) $run->failureReason())
            ->and($run->steps()->orderBy('sequence')->pluck('name')->all())->toBe(fixtureUpdateSteps())
            ->and($run->steps()->where('name', MaintenanceOnStep::NAME)->sole()->status->value)->toBe('succeeded')
            ->and(app(RestorePointGate::class)->isVerified($run))->toBeTrue()
            ->and([$change->result, $change->version_before, $change->version_after])->toBe([ChangeResult::Updated, '1.0.0', '1.1.0'])
            ->and(fixtureUpdateVersion($driver))->toBe('1.1.0')
            ->and($driver->wp(['maintenance-mode', 'is-active'])->successful())->toBeFalse();
    });
});

it('skips, untouched, an update that declares a PHP above the one serving wp74 (layer 1)', function (): void {
    fixtureUpdate('wp74', fixtureUpdatePluginZip('1.1.0'), static function (Run $run, SiteDriver $driver): void {
        $skip = $run->changes()->where('slug', IN_PLACE_PLUGIN)->sole();

        expect($run->status)->toBe(RunStatus::Succeeded, (string) $run->failureReason())
            // Nothing to apply: the run ends after the plan, with no restore point.
            ->and($run->steps()->orderBy('sequence')->pluck('name')->all())->toBe([InventoryStep::NAME, PlanStep::NAME])
            ->and([$skip->result, $skip->reason, $skip->version_before, $skip->version_after])->toBe([ChangeResult::Skipped, SkipReason::PhpIncompatible, '1.0.0', '1.1.0'])
            ->and($skip->reason_detail)->toStartWith('Requereix PHP 8.1 i el lloc té PHP 7.4')
            ->and(fixtureUpdateVersion($driver))->toBe('1.0.0');
    }, offer: ['requires_php' => '8.1']);
});

it('skips, untouched, an update whose code PHPCompatibility rejects for the PHP of wp83 (layer 2)', function (): void {
    $package = fixtureUpdatePluginZip('1.1.0', "if (false) {\n    mysql_connect('localhost');\n}");

    fixtureUpdate(IN_PLACE_FIXTURE, $package, static function (Run $run, SiteDriver $driver): void {
        $skip = $run->changes()->where('slug', IN_PLACE_PLUGIN)->sole();

        expect($run->status)->toBe(RunStatus::Succeeded, (string) $run->failureReason())
            ->and($run->steps()->orderBy('sequence')->pluck('name')->all())->toBe([InventoryStep::NAME, PlanStep::NAME])
            ->and([$skip->result, $skip->reason])->toBe([ChangeResult::Skipped, SkipReason::StaticAnalysis])
            ->and($skip->reason_detail)->toStartWith('PHPCompatibility: ')->toContain('per a PHP 8.3')->toContain(IN_PLACE_PLUGIN.'.php:')
            ->and(fixtureUpdateVersion($driver))->toBe('1.0.0');
    });
});

it('rolls wp83 back, files and database, when the update leaves the wrong version', function (): void {
    fixtureUpdate(IN_PLACE_FIXTURE, fixtureUpdatePluginZip('1.0.5'), static function (Run $run, SiteDriver $driver): void {
        $recovery = $run->steps()->where('kind', RunStepKind::Recovery->value)->orderBy('sequence')->get();

        expect($run->status)->toBe(RunStatus::RolledBack, (string) $run->failureReason())
            ->and($run->result_summary['failed_step'] ?? null)->toBe('update:plugin:'.IN_PLACE_PLUGIN)
            ->and($recovery->pluck('name')->all())->toBe([MaintenanceForceOnStep::NAME, FilesRollbackStep::NAME, DatabaseRollbackStep::NAME, MaintenanceRestoreStep::NAME])
            ->and($recovery->pluck('status')->map->value->all())->toBe(['succeeded', 'succeeded', 'succeeded', 'succeeded'])
            ->and($recovery->get(2)?->result['restored'] ?? null)->toBeTrue()
            ->and(fixtureUpdateVersion($driver))->toBe('1.0.0')
            ->and($driver->wp(['option', 'get', 'blogname'])->successful())->toBeTrue();
    });
});

it('rolls wp83 back to an identical site when the updated plugin breaks it with a fatal', function (): void {
    $token = bin2hex(random_bytes(6));
    $before = [];

    fixtureUpdate(IN_PLACE_FIXTURE, fixtureUpdatePluginZip('1.1.0', fixtureUpdateBreakingCode($token, false)), static function (Run $run, SiteDriver $driver, Site $site) use ($token, &$before): void {
        $recovery = $run->steps()->where('kind', RunStepKind::Recovery->value)->orderBy('sequence')->get();
        $smoke = $run->steps()->where('name', SmokeCheckStep::NAME)->sole();
        $diff = fixtureUpdateTreeDiff($before, fixtureUpdateTree(IN_PLACE_FIXTURE, $site));

        expect($run->status)->toBe(RunStatus::RolledBack, (string) $run->failureReason())
            ->and($run->result_summary['failed_step'] ?? null)->toBe(SmokeCheckStep::NAME)
            ->and($smoke->output)->toContain("wpup-e2e-{$token}")
            ->and($recovery->pluck('name')->all())->toBe([MaintenanceForceOnStep::NAME, FilesRollbackStep::NAME, DatabaseRollbackStep::NAME, MaintenanceRestoreStep::NAME])
            ->and($recovery->pluck('status')->map->value->all())->toBe(['succeeded', 'succeeded', 'succeeded', 'succeeded'])
            ->and($recovery->get(2)?->result['restored'] ?? null)->toBeTrue()
            // The files as they were (versions included) and the database without what the broken version wrote.
            ->and($diff)->toBe([], 'fitxers diferents: '.implode(' | ', array_slice($diff, 0, 10)))
            ->and(fixtureUpdateVersion($driver))->toBe('1.0.0')
            ->and($driver->wp(['plugin', 'is-active', IN_PLACE_PLUGIN])->successful())->toBeTrue()
            ->and(fixtureUpdateMarker($driver))->toBe('')
            ->and($driver->wp(['maintenance-mode', 'is-active'])->successful())->toBeFalse();
    }, activate: true, before: static function (Site $site) use (&$before): void {
        $before = fixtureUpdateTree(IN_PLACE_FIXTURE, $site);
    });
});

it('restores only the files of wp83, and names the order, when an order arrives during the update', function (): void {
    $token = bin2hex(random_bytes(6));
    $before = [];
    /** @var list<int>|null $existing orders of the fixture before the run */
    $existing = null;
    $ordersOf = static fn (SiteDriver $driver): array => array_values(array_map(intval(...), array_filter(explode(',', trim(
        $driver->wp(['eval', 'echo implode(",", wc_get_orders(array("limit" => -1, "return" => "ids", "status" => "any")));'])->stdout,
    )))));
    $driver = app(DriverFactory::class)->forSite(Fixtures::site(IN_PLACE_FIXTURE), AuditActor::system('tests'));

    try {
        fixtureUpdate(IN_PLACE_FIXTURE, fixtureUpdatePluginZip('1.1.0', fixtureUpdateBreakingCode($token, true)), static function (Run $run, SiteDriver $driver, Site $site) use ($token, &$before, &$existing, $ordersOf): void {
            $new = array_values(array_diff($ordersOf($driver), $existing ?? []));
            $database = $run->steps()->where('name', DatabaseRollbackStep::NAME)->sole();
            $diff = fixtureUpdateTreeDiff($before, fixtureUpdateTree(IN_PLACE_FIXTURE, $site));

            expect($new)->not->toBe([], "el lloc no ha rebut cap comanda durant l'actualització")
                ->and($run->status)->toBe(RunStatus::RolledBack, (string) $run->failureReason())
                ->and($run->result_summary['failed_step'] ?? null)->toBe(SmokeCheckStep::NAME)
                ->and($run->steps()->where('name', FilesRollbackStep::NAME)->sole()->status->value)->toBe('succeeded')
                // AGENTS.md rule 4: the database is never restored over a new order, and the warning names it.
                ->and($database->status->value)->toBe('succeeded')
                ->and($database->result['restored'] ?? null)->toBeFalse()
                ->and($database->summary)->toStartWith('BD no restaurada')
                ->and($database->output)->toContain("#{$new[0]} (nova, comanda, processing")
                ->and(data_get($database->result, 'new_writes.orders.*.id'))->toContain($new[0])
                // The files as they were; the database untouched: the order and what the broken version wrote are still there.
                ->and($diff)->toBe([], 'fitxers diferents: '.implode(' | ', array_slice($diff, 0, 10)))
                ->and(fixtureUpdateVersion($driver))->toBe('1.0.0')
                ->and(trim($driver->wp(['eval', "echo wc_get_order({$new[0]}) ? 'yes' : 'no';"])->stdout))->toBe('yes')
                ->and(fixtureUpdateMarker($driver))->toBe($token)
                ->and($driver->wp(['maintenance-mode', 'is-active'])->successful())->toBeFalse();
        }, activate: true, before: static function (Site $site, SiteDriver $driver) use (&$before, &$existing, $ordersOf): void {
            $before = fixtureUpdateTree(IN_PLACE_FIXTURE, $site);
            $existing = $ordersOf($driver);
        });
    } finally {
        // The database was (rightly) not restored: what the run left in it goes now.
        foreach ($existing === null ? [] : array_diff($ordersOf($driver), $existing) as $id) {
            $driver->wp(['eval', "\$o = wc_get_order({$id}); if (\$o) { \$o->delete(true); } echo 'ok';"]);
        }
        $driver->wp(['option', 'delete', 'wpup_e2e_marker', '--skip-plugins', '--skip-themes']);
    }
});
