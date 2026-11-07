<?php

declare(strict_types=1);

use App\Domain\Artifacts\ArtifactStore;
use App\Domain\Artifacts\PackageSource;
use App\Domain\Artifacts\PackageUrlGuard;
use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Backups\BackupStep;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\NewWritesDetector;
use App\Domain\Backups\RestorePointGate;
use App\Domain\Backups\RestorePointGateStep;
use App\Domain\Backups\RestorePointRetention;
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\RestoreSelection;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Backups\SiteRestore;
use App\Domain\Drivers\Data\AppliedUpdate;
use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Data\UpdatePackage;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Locks\LockedException;
use App\Domain\Locks\SiteLockManager;
use App\Domain\Maintenance\MaintenanceCheckStep;
use App\Domain\Maintenance\MaintenanceForceOnStep;
use App\Domain\Maintenance\MaintenanceGuard;
use App\Domain\Maintenance\MaintenanceOffStep;
use App\Domain\Maintenance\MaintenanceOnStep;
use App\Domain\Maintenance\MaintenanceRestoreStep;
use App\Domain\Maintenance\MaintenanceSteps;
use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Planning\PlanStep;
use App\Domain\Planning\UpdatePlan;
use App\Domain\Rollback\DatabaseRollbackStep;
use App\Domain\Rollback\FilesRollbackStep;
use App\Domain\Rollback\RevertedChanges;
use App\Domain\Rollback\RollbackSteps;
use App\Domain\Runs\Concurrency\ConcurrencyGate;
use App\Domain\Runs\Concurrency\ConcurrencyLimitReached;
use App\Domain\Runs\Exceptions\RunException;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\RunEngine;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Updates\InPlaceUpdatePipeline;
use App\Domain\Updates\PhpLogBaselineStep;
use App\Domain\Updates\PostUpdateSettings;
use App\Domain\Updates\PostUpdateStep;
use App\Domain\Updates\SmokeCheckSettings;
use App\Domain\Updates\SmokeCheckStep;
use App\Domain\Updates\SmokeCheckSteps;
use App\Domain\Updates\UpdateChanges;
use App\Domain\Updates\UpdateSteps;
use App\Enums\AuditChannel;
use App\Enums\BackupTargetType;
use App\Enums\ChangeResult;
use App\Enums\ComponentType;
use App\Enums\PhpVersionSource;
use App\Enums\RunStatus;
use App\Enums\RunStepKind;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Enums\SkipReason;
use App\Enums\UpdateStrategy;
use App\Enums\UserRole;
use App\Models\Backup;
use App\Models\Run;
use App\Models\RunStep;
use App\Models\Site;
use App\Models\SiteProfile;
use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeDriver;
use Tests\Support\Runs\ScriptedStep;

/*
 * The update run with the in-place strategy (UZEROWPUP-60) over FakeDriver:
 * inventory and plan → verified restore point → updates → post-update, and its
 * own rollback (files, then the database only without new writes) when a step
 * from the first update on fails.
 */

/** A FakeDriver whose updates also write to the database (a plugin migration), and one of them can fail. */
class InPlaceDriver extends FakeDriver
{
    public ?string $failOn = null;

    /** Runs right after the theme is updated. */
    public ?Closure $onTheme = null;

    /** @var list<bool> the maintenance mode at each database restore */
    public array $maintenanceAtRestore = [];

    public function restoreDatabase(RemoteFile $dump): void
    {
        $this->maintenanceAtRestore[] = $this->maintenance;
        parent::restoreDatabase($dump);
    }

    /** Runs once the code archive of the restore point is made (after the database dump and its watermark). */
    public ?Closure $afterBackup = null;

    public function archiveFiles(string $remotePath, array $excludes): RemoteFile
    {
        $archive = parent::archiveFiles($remotePath, $excludes);
        if ($this->afterBackup !== null) {
            ($this->afterBackup)($this);
        }

        return $archive;
    }

    public function applyUpdate(UpdatePackage $package): AppliedUpdate
    {
        if ($package->slug === $this->failOn) {
            $this->writeCalls[] = 'applyUpdate';

            throw new RemoteCommandFailed("Error: no s'ha pogut descomprimir {$package->slug}");
        }
        $applied = parent::applyUpdate($package);
        if ($package->type === ComponentType::Theme && $this->onTheme !== null) {
            ($this->onTheme)($this);
        }
        $this->options["{$package->slug}_db_version"] = $package->targetVersion;

        return $applied;
    }
}

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    config(['wpup.runs.heartbeat_seconds' => 1, 'wpup.smoke_check.http_attempt_pause_seconds' => 0,
        'wpup.artifacts.dir' => sys_get_temp_dir().'/wpup-artifacts-'.bin2hex(random_bytes(6))]);
    // Nothing is downloaded (the packages are the run's own artifacts); only the smoke check reaches the home.
    Http::fake(['botiga.test/*' => Http::response('<html>botiga</html>', 200), '*' => Http::response('', 500)]);
    app(SecretRedactor::class)->reset();
});

afterEach(function (): void {
    $dir = config('wpup.artifacts.dir');
    if (is_string($dir)) {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

/**
 * The changes the scripted plan step plans: two updates and a skip.
 *
 * @return list<PlannedChange>
 */
function inPlaceChanges(): array
{
    return [
        new PlannedChange(ComponentType::Plugin, 'classic-editor', '1.6.3', '1.6.4', PlanAction::Update),
        new PlannedChange(ComponentType::Plugin, 'hello', '1.6', '2.0', PlanAction::Skip, SkipReason::MajorRequiresStaging),
        new PlannedChange(ComponentType::Theme, 'storefront', '4.6.0', '4.6.1', PlanAction::Update),
    ];
}

/**
 * Registers the update pipeline over $driver: real backup, gate, update and
 * rollback steps; the inventory and the plan scripted (the plan as PlanStep
 * stores it, with the artifacts its analysis would have fetched).
 *
 * @param  list<PlannedChange>  $changes
 */
function inPlacePipeline(FakeDriver $driver, array $changes): void
{
    $driverFor = static fn (Site $s, AuditActor $a): SiteDriver => $driver;
    app(BackupTargetRegistry::class)->register(BackupTargetType::SiteLocal, static fn (): BackupTarget => new SiteLocalTarget(
        $driver, app(SecretRedactor::class), app(HttpFactory::class), app(SiteLocalSettings::class), 'https://fake.test',
    ));

    $inventory = new ScriptedStep('inventory', static function (StepContext $c) use ($driver): StepOutcome {
        $snapshot = app(SiteInventory::class)->take($c->run->site ?? throw new LogicException, $driver, $c->run, probeWeb: false);

        return StepOutcome::succeeded('inventari', '', ['snapshot_id' => $snapshot->id]);
    });
    $plan = new ScriptedStep(PlanStep::NAME, static function (StepContext $c) use ($changes): StepOutcome {
        $store = app(ArtifactStore::class);
        foreach ($changes as $change) {
            if ($change->action === PlanAction::Update) {
                $temporary = $store->temporaryFile($c->run->id);
                $bytes = "package {$change->slug} {$change->targetVersion}";
                file_put_contents($temporary, $bytes);
                $source = PackageSource::create($change->type, $change->slug, $change->targetVersion, "https://downloads.wordpress.org/plugin/{$change->slug}.{$change->targetVersion}.zip", app(PackageUrlGuard::class));
                $store->commit($c->run->id, $source, $temporary, hash('sha256', $bytes), strlen($bytes));
            }
        }
        $plan = new UpdatePlan('8.3.14', PhpVersionSource::PleskHandler, true, null, $changes);

        return StepOutcome::succeeded($plan->summary(), '', $plan->toArray());
    });

    $pipeline = new InPlaceUpdatePipeline(
        $inventory,
        $plan,
        new BackupStep(app(RestorePointService::class), $driverFor, app(RunAuthorization::class), 600),
        new RestorePointGateStep(app(RestorePointGate::class), 60),
        new UpdateSteps(app(ArtifactStore::class), $driverFor, app(RunAuthorization::class), new UpdateChanges, app(SecretRedactor::class),
            new PostUpdateSettings(6600, 1800, 120, 900, []), 60, 60),
        new SmokeCheckSteps($driverFor, app(RunAuthorization::class), app(HttpFactory::class), app(SecretRedactor::class), app(SmokeCheckSettings::class), 15, 300),
        new MaintenanceSteps(new MaintenanceGuard($driverFor, app(RunAuthorization::class), app(SecretRedactor::class), true, 60), 60),
        new RollbackSteps(app(RestoreSelection::class), app(SiteRestore::class), app(RevertedChanges::class), app(NewWritesDetector::class),
            $driverFor, app(RunAuthorization::class), app(AuditLogger::class), app(SecretRedactor::class), 60, 60),
    );
    app(PipelineResolver::class)->register(RunType::Update, static fn (Run $run) => $pipeline->for($run));
}

/**
 * A shop with its site-local destination, and an update run queued by an operator.
 *
 * @param  array<string, mixed>  $attributes
 * @param  bool|null  $maintenance  the profile's maintenance_mode (null: the default, on)
 */
function inPlaceRun(array $attributes = [], ?bool $maintenance = null): Run
{
    $site = Site::factory()->create(['name' => 'botiga', 'url' => 'https://botiga.test']);
    SiteProfile::factory()->create(['site_id' => $site->id, 'maintenance_mode' => $maintenance]);
    app(SiteLocalDestination::class)->attach($site);
    $user = User::factory()->create(['role' => UserRole::Operator]);

    return Run::factory()->create(['type' => RunType::Update, 'site_id' => $site->id, 'user_id' => $user->id, 'actor' => $user->name,
        'channel' => AuditChannel::Cli, 'strategy' => UpdateStrategy::InPlace, ...$attributes]);
}

/**
 * @return list<array{string, string, string}>
 */
function inPlaceSteps(Run $run): array
{
    return array_values($run->steps()->orderBy('sequence')->get()
        ->map(static fn (RunStep $s): array => [$s->kind->value, $s->name, $s->status->value])
        ->all());
}

it('backs up, checks the restore point, updates in the plan order and migrates', function (): void {
    $driver = new InPlaceDriver;
    inPlacePipeline($driver, inPlaceChanges());

    $run = app(RunEngine::class)->execute(inPlaceRun());

    expect($run->status)->toBe(RunStatus::Succeeded, (string) $run->failureReason())
        ->and(inPlaceSteps($run))->toBe([
            ['forward', 'inventory', 'succeeded'],
            ['forward', 'plan', 'succeeded'],
            ['forward', MaintenanceCheckStep::NAME, 'succeeded'],
            ['forward', BackupStep::NAME, 'succeeded'],
            ['forward', PhpLogBaselineStep::NAME, 'succeeded'],
            ['forward', RestorePointGateStep::NAME, 'succeeded'],
            ['forward', MaintenanceOnStep::NAME, 'succeeded'],
            ['forward', 'update:plugin:classic-editor', 'succeeded'],
            ['forward', 'update:theme:storefront', 'succeeded'],
            ['forward', PostUpdateStep::NAME, 'succeeded'],
            ['forward', MaintenanceOffStep::NAME, 'succeeded'],
            ['forward', SmokeCheckStep::NAME, 'succeeded'],
            ['forward', MaintenanceRestoreStep::NAME, 'succeeded'],
        ])
        ->and($driver->maintenance)->toBeFalse()
        ->and($driver->components['plugin:classic-editor']->version)->toBe('1.6.4')
        ->and($driver->components['theme:storefront']->version)->toBe('4.6.1')
        // The skipped major is not touched.
        ->and($driver->writeCalls)->not->toContain('restoreFiles')
        ->and(app(RestorePointGate::class)->isVerified($run))->toBeTrue()
        ->and($run->changes()->where('step', UpdateChanges::STEP)->pluck('result')->all())->toBe([ChangeResult::Updated, ChangeResult::Updated]);

    // The backup comes before the first change to the site.
    expect((int) array_search('dumpDatabase', $driver->writeCalls, true))->toBeLessThan((int) array_search('applyUpdate', $driver->writeCalls, true));
});

it('ends after the plan without touching the site when there is nothing to apply', function (): void {
    $driver = new InPlaceDriver;
    inPlacePipeline($driver, [
        new PlannedChange(ComponentType::Plugin, 'hello', '1.6', '2.0', PlanAction::Skip, SkipReason::MajorRequiresStaging),
    ]);

    $run = app(RunEngine::class)->execute(inPlaceRun());

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and(inPlaceSteps($run))->toBe([['forward', 'inventory', 'succeeded'], ['forward', 'plan', 'succeeded']])
        ->and($driver->writeCalls)->toBe([])
        ->and(Backup::query()->where('run_id', $run->id)->count())->toBe(0);
});

it('rolls back the files and then the database when an update fails', function (): void {
    $driver = new InPlaceDriver;
    $driver->failOn = 'storefront';
    inPlacePipeline($driver, inPlaceChanges());

    $run = app(RunEngine::class)->execute(inPlaceRun());

    expect($run->status)->toBe(RunStatus::RolledBack, (string) $run->failureReason())
        ->and($run->result_summary['failed_step'] ?? null)->toBe('update:theme:storefront')
        ->and(inPlaceSteps($run))->toBe([
            ['forward', 'inventory', 'succeeded'],
            ['forward', 'plan', 'succeeded'],
            ['forward', MaintenanceCheckStep::NAME, 'succeeded'],
            ['forward', BackupStep::NAME, 'succeeded'],
            ['forward', PhpLogBaselineStep::NAME, 'succeeded'],
            ['forward', RestorePointGateStep::NAME, 'succeeded'],
            ['forward', MaintenanceOnStep::NAME, 'succeeded'],
            ['forward', 'update:plugin:classic-editor', 'succeeded'],
            ['forward', 'update:theme:storefront', 'failed'],
            ['forward', PostUpdateStep::NAME, 'pending'],
            ['forward', MaintenanceOffStep::NAME, 'pending'],
            ['forward', SmokeCheckStep::NAME, 'pending'],
            ['forward', MaintenanceRestoreStep::NAME, 'pending'],
            ['recovery', MaintenanceForceOnStep::NAME, 'succeeded'],
            ['recovery', FilesRollbackStep::NAME, 'succeeded'],
            ['recovery', DatabaseRollbackStep::NAME, 'succeeded'],
            ['recovery', MaintenanceRestoreStep::NAME, 'succeeded'],
        ])
        // The shop stays closed during the whole rollback, and opens again at the end.
        ->and($driver->maintenanceAtRestore)->toBe([true])
        ->and($driver->maintenance)->toBeFalse()
        // The code and the database are back as they were before the run.
        ->and($driver->components['plugin:classic-editor']->version)->toBe('1.6.3')
        ->and($driver->options)->not->toHaveKey('classic-editor_db_version')
        ->and($run->steps()->where('name', DatabaseRollbackStep::NAME)->sole()->result['restored'] ?? null)->toBeTrue()
        ->and($run->changes()->where('slug', 'classic-editor')->where('step', UpdateChanges::STEP)->sole()->result)->toBe(ChangeResult::Reverted)
        // Its restore point is held until an operator reviews the run (ADR-0009).
        ->and(RestorePointRetention::isIncident($run))->toBeTrue();

    // Files first, then the database.
    expect((int) array_search('restoreFiles', $driver->writeCalls, true))->toBeLessThan((int) array_search('restoreDatabase', $driver->writeCalls, true));
});

it('rolls back when the post-update migration fails', function (): void {
    $driver = new InPlaceDriver;
    $driver->failingCommands = ['core update-db'];
    inPlacePipeline($driver, inPlaceChanges());

    $run = app(RunEngine::class)->execute(inPlaceRun());

    expect($run->status)->toBe(RunStatus::RolledBack)
        ->and($run->result_summary['failed_step'] ?? null)->toBe(PostUpdateStep::NAME)
        ->and($driver->components['plugin:classic-editor']->version)->toBe('1.6.3')
        ->and($driver->components['theme:storefront']->version)->toBe('4.6.0');
});

it('rolls back when the smoke check finds a new fatal in the PHP log of the web', function (): void {
    $driver = new InPlaceDriver;
    inPlacePipeline($driver, inPlaceChanges());
    $run = inPlaceRun();
    // The updated theme breaks a page: PHP logs a fatal after the baseline.
    $driver->onTheme = static function (FakeDriver $d): void {
        $d->phpLog[] = '[06-Nov-2026 03:12:00 UTC] PHP Fatal error:  Uncaught Error: Call to undefined function storefront_x() in /themes/storefront/functions.php:12';
    };

    $run = app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::RolledBack)
        ->and($run->result_summary['failed_step'] ?? null)->toBe(SmokeCheckStep::NAME)
        ->and($run->failureReason())->toContain('fatal')
        ->and($driver->components['theme:storefront']->version)->toBe('4.6.0')
        ->and($driver->components['plugin:classic-editor']->version)->toBe('1.6.3')
        // Switched off for the smoke check, on again for the whole rollback, off at the end.
        ->and($driver->maintenanceAtRestore)->toBe([true])
        ->and($driver->maintenance)->toBeFalse();
});

it('closes the shop during the rollback even when its profile asks for no maintenance mode', function (): void {
    $driver = new InPlaceDriver;
    $driver->failOn = 'storefront';
    inPlacePipeline($driver, inPlaceChanges());

    $run = app(RunEngine::class)->execute(inPlaceRun(maintenance: false));

    expect($run->status)->toBe(RunStatus::RolledBack)
        ->and($run->steps()->where('name', MaintenanceOnStep::NAME)->sole()->status)->toBe(RunStepStatus::Skipped)
        ->and($driver->maintenanceAtRestore)->toBe([true])
        ->and($driver->maintenance)->toBeFalse();
});

it('switches the maintenance mode off before the smoke check, which must see the real site', function (): void {
    $driver = new InPlaceDriver;
    inPlacePipeline($driver, inPlaceChanges());
    $seen = null;
    Http::fake(['botiga.test/*' => static function () use ($driver, &$seen) {
        $seen = $driver->maintenance;

        return Http::response('<html>botiga</html>', 200);
    }, '*' => Http::response('', 500)]);

    $run = app(RunEngine::class)->execute(inPlaceRun());

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($seen)->toBeFalse();
});

it('does not update a site that is already in maintenance: no copy, nothing touched, its mode left alone', function (?bool $profile): void {
    $driver = new InPlaceDriver;
    $driver->maintenance = true;
    inPlacePipeline($driver, inPlaceChanges());

    $run = app(RunEngine::class)->execute(inPlaceRun(maintenance: $profile));

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->result_summary['failed_step'] ?? null)->toBe(MaintenanceCheckStep::NAME)
        ->and($run->failureReason())->toContain('ja és en mode manteniment')
        ->and($driver->writeCalls)->toBe([])
        ->and(Backup::query()->where('run_id', $run->id)->count())->toBe(0)
        ->and($driver->maintenance)->toBeTrue()
        ->and($run->steps()->where('kind', '!=', RunStepKind::Forward->value)->count())->toBe(0);
})->with(['profile with maintenance' => [true], 'profile without maintenance' => [false]]);

it('stops without compensations when the maintenance mode cannot be switched on', function (): void {
    $driver = new class extends InPlaceDriver
    {
        public function setMaintenanceMode(bool $enabled): void
        {
            throw new RemoteCommandFailed('maintenance-mode ha fallat');
        }
    };
    inPlacePipeline($driver, inPlaceChanges());

    $run = app(RunEngine::class)->execute(inPlaceRun());

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->result_summary['failed_step'] ?? null)->toBe(MaintenanceOnStep::NAME)
        ->and($driver->writeCalls)->not->toContain('applyUpdate')
        ->and($run->steps()->where('kind', '!=', RunStepKind::Forward->value)->count())->toBe(0);
});

it('fails without undoing a verified update when the maintenance mode cannot be put back', function (): void {
    $driver = new class extends InPlaceDriver
    {
        public int $switches = 0;

        public function setMaintenanceMode(bool $enabled): void
        {
            // On, off before the smoke check, then the last restore fails.
            if (++$this->switches === 3) {
                throw new RemoteCommandFailed('maintenance-mode ha fallat');
            }
            parent::setMaintenanceMode($enabled);
        }
    };
    inPlacePipeline($driver, inPlaceChanges());

    $run = app(RunEngine::class)->execute(inPlaceRun());

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->result_summary['failed_step'] ?? null)->toBe(MaintenanceRestoreStep::NAME)
        ->and($driver->components['plugin:classic-editor']->version)->toBe('1.6.4')
        ->and($driver->writeCalls)->not->toContain('restoreFiles')
        ->and($run->steps()->where('kind', '!=', RunStepKind::Forward->value)->count())->toBe(0);
});

it('restores only the files of a shop with a new order since the copy, and says which orders', function (): void {
    $driver = new InPlaceDriver;
    $driver->failOn = 'storefront';
    inPlacePipeline($driver, inPlaceChanges());
    $run = inPlaceRun();
    // An order enters once the restore point is made.
    $order = ['id' => 77, 'type' => 'shop_order', 'status' => 'wc-processing', 'created' => '2026-11-06 03:10:00', 'modified' => '2026-11-06 03:10:00'];
    $driver->afterBackup = static function (FakeDriver $d) use ($order): void {
        $d->orders[] = $order;
    };

    $run = app(RunEngine::class)->execute($run);

    $database = $run->steps()->where('name', DatabaseRollbackStep::NAME)->sole();
    expect($run->status)->toBe(RunStatus::RolledBack)
        ->and($driver->writeCalls)->toContain('restoreFiles')
        ->and($driver->writeCalls)->not->toContain('restoreDatabase')
        ->and($driver->components['plugin:classic-editor']->version)->toBe('1.6.3')
        // The database is the one with the order and the plugin's migration.
        ->and($driver->orders)->toContain($order)
        ->and($driver->options['classic-editor_db_version'] ?? null)->toBe('1.6.4')
        ->and($database->result['restored'] ?? null)->toBeFalse()
        ->and(json_encode($database->result['new_writes'] ?? null))->toContain('77')
        ->and($database->result['force_command'] ?? null)->toContain('--accept-data-loss');
});

it('fails without touching the code when the restore point cannot be made', function (): void {
    $driver = new InPlaceDriver;
    $driver->tablesFail = true;
    inPlacePipeline($driver, inPlaceChanges());

    $run = app(RunEngine::class)->execute(inPlaceRun());

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->result_summary['failed_step'] ?? null)->toBe(BackupStep::NAME)
        ->and($driver->writeCalls)->not->toContain('applyUpdate')
        ->and($run->steps()->where('kind', '!=', 'forward')->count())->toBe(0);
});

it('never updates without a verified restore point', function (): void {
    $outcome = (new RestorePointGateStep(app(RestorePointGate::class), 60))->run(new StepContext(inPlaceRun(), false));

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain('no té cap punt de restauració verificat');
});

it('is the pipeline registered for update runs, starting with the inventory and the plan', function (): void {
    $pipeline = app(PipelineResolver::class)->resolve(inPlaceRun());

    expect($pipeline->names())->toBe(['inventory', PlanStep::NAME]);
});

it('refuses the staging strategy until F7', function (): void {
    $driver = new InPlaceDriver;
    inPlacePipeline($driver, inPlaceChanges());

    $run = inPlaceRun(['strategy' => UpdateStrategy::StagingFirst]);

    expect(fn () => app(RunEngine::class)->execute($run))->toThrow(RunException::class);
    $run->refresh();
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failureReason())->toContain('F7')
        ->and($driver->writeCalls)->toBe([]);
});

it('waits while another update holds the only global slot, or while the site is locked', function (): void {
    $driver = new InPlaceDriver;
    inPlacePipeline($driver, inPlaceChanges());
    $run = inPlaceRun();
    $other = Run::factory()->create(['type' => RunType::Update]);
    $slot = app(ConcurrencyGate::class)->acquire($other, 'una-altra');

    expect(fn () => app(RunEngine::class)->execute($run))->toThrow(ConcurrencyLimitReached::class)
        ->and($run->fresh()?->status)->toBe(RunStatus::Queued);

    $slot->release();
    $lease = app(SiteLockManager::class)->acquire($run->site ?? throw new LogicException, 'una altra execució');

    expect(fn () => app(RunEngine::class)->execute($run))->toThrow(LockedException::class)
        ->and($run->fresh()?->status)->toBe(RunStatus::Queued)
        ->and($driver->writeCalls)->toBe([]);

    $lease->release();
});
