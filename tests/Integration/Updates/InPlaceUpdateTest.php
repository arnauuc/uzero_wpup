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
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Drivers\DriverFactory;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Maintenance\MaintenanceCheckStep;
use App\Domain\Maintenance\MaintenanceForceOnStep;
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
use App\Domain\Rollback\RollbackSteps;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\RunEngine;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Updates\InPlaceUpdatePipeline;
use App\Domain\Updates\PhpLogBaselineStep;
use App\Domain\Updates\SmokeCheckStep;
use App\Domain\Updates\SmokeCheckSteps;
use App\Domain\Updates\UpdateSteps;
use App\Enums\AuditChannel;
use App\Enums\BackupTargetType;
use App\Enums\ComponentType;
use App\Enums\PhpVersionSource;
use App\Enums\RunStatus;
use App\Enums\RunStepKind;
use App\Enums\RunType;
use App\Enums\UpdateStrategy;
use App\Enums\UserRole;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Run;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Redis;
use Tests\Contracts\SiteDriverContract;
use Tests\Support\Fixtures;
use Tests\Support\Runs\ScriptedStep;

/*
 * The in-place update run (UZEROWPUP-60) end to end against a Docker test site
 * (make fixtures-up), never a real one: inventory, a real restore point, the
 * update of a tiny test plugin from the run's artifact and the post-update
 * step, and, when the update leaves the wrong version, the run's own
 * rollback of the files and the database. The plan is scripted (it would
 * otherwise depend on WordPress.org); the plan run has its own tests.
 * The store root is the test's own; the docroot is mirrored back afterwards.
 */

const IN_PLACE_FIXTURE = 'wp83';

if (! Fixtures::up(IN_PLACE_FIXTURE)) {
    it('updates a test site in place and rolls it back')->skip('Llocs de prova aturats: make fixtures-up');

    return;
}

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    config(['wpup.artifacts.dir' => sys_get_temp_dir().'/wpup-artifacts-'.bin2hex(random_bytes(6))]);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg(artifactsRoot()));
});

/**
 * Runs an update of the contract plugin from 1.0.0 to 1.1.0 whose package
 * really holds $packaged, and leaves the docroot as it was.
 *
 * @param  Closure(Run, SiteDriver): void  $check
 */
function inPlaceFixtureUpdate(string $packaged, Closure $check): void
{
    $storeRoot = 'restore-points/it-update-'.bin2hex(random_bytes(6));
    $site = Fixtures::site(IN_PLACE_FIXTURE);
    $site->update(['url' => 'http://'.IN_PLACE_FIXTURE.':8083']);
    app(SiteLocalDestination::class)->attach($site);
    app(BackupTargetRegistry::class)->register(BackupTargetType::SiteLocal, static fn (BackupTargetRecord $r, Site $s, AuditActor $a): BackupTarget => new SiteLocalTarget(
        app(DriverFactory::class)->forSite($s, $a), app(SecretRedactor::class), app(HttpFactory::class), app(SiteLocalSettings::class)->withRoot($storeRoot), $s->url,
    ));
    $driver = app(DriverFactory::class)->forSite($site, AuditActor::system('tests'));
    $archive = $driver->archiveFiles(SiteDriverContract::remoteName('files.tar.gz'), ['wp-content/uploads']);
    $old = SiteDriverContract::contractPluginZip('1.0.0');
    $new = SiteDriverContract::contractPluginZip($packaged);

    $driverFor = static fn (Site $s, AuditActor $a): SiteDriver => app(DriverFactory::class)->forSite($s, $a);
    $plan = new ScriptedStep(PlanStep::NAME, static function (StepContext $c) use ($new): StepOutcome {
        $store = app(ArtifactStore::class);
        $temporary = $store->temporaryFile($c->run->id);
        copy($new, $temporary);
        $source = PackageSource::create(ComponentType::Plugin, 'wpup-contract-plugin', '1.1.0', 'https://updates.example.com/wpup-contract-plugin.zip', app(PackageUrlGuard::class));
        $store->commit($c->run->id, $source, $temporary, (string) hash_file('sha256', $new), (int) filesize($new));
        $plan = new UpdatePlan('8.3', PhpVersionSource::PleskHandler, true, null, [
            new PlannedChange(ComponentType::Plugin, 'wpup-contract-plugin', '1.0.0', '1.1.0', PlanAction::Update),
        ]);

        return StepOutcome::succeeded($plan->summary(), '', $plan->toArray());
    });
    $pipeline = new InPlaceUpdatePipeline(
        new InventoryStep(app(SiteInventory::class), $driverFor, app(RunAuthorization::class), 600, readOnlySite: true),
        $plan,
        new BackupStep(app(RestorePointService::class), $driverFor, app(RunAuthorization::class), 3600),
        new RestorePointGateStep(app(RestorePointGate::class), 60),
        app(UpdateSteps::class),
        app(SmokeCheckSteps::class),
        app(MaintenanceSteps::class),
        app(RollbackSteps::class),
    );
    app(PipelineResolver::class)->register(RunType::Update, static fn (Run $run) => $pipeline->for($run));
    $user = User::factory()->create(['role' => UserRole::Operator]);

    try {
        $driver->applyUpdate(SiteDriverContract::package($old, '1.0.0'));

        $run = app(RunEngine::class)->execute(Run::factory()->create(['type' => RunType::Update, 'site_id' => $site->id, 'user_id' => $user->id,
            'actor' => $user->name, 'channel' => AuditChannel::Cli, 'strategy' => UpdateStrategy::InPlace]));

        $check($run, $driver);
    } finally {
        $driver->restoreFiles($archive);
        $driver->remove($archive->path);
        Fixtures::run(IN_PLACE_FIXTURE, 'rm -rf "$HOME/.wpup/"'.escapeshellarg($storeRoot));
        @unlink($old);
        @unlink($new);
    }
}

it('updates a component of wp83 in place behind a verified restore point', function (): void {
    inPlaceFixtureUpdate('1.1.0', static function (Run $run, SiteDriver $driver): void {
        expect($run->status)->toBe(RunStatus::Succeeded, (string) $run->failureReason())
            ->and($run->steps()->orderBy('sequence')->pluck('name')->all())->toBe([
                InventoryStep::NAME, PlanStep::NAME, MaintenanceCheckStep::NAME, BackupStep::NAME, PhpLogBaselineStep::NAME, RestorePointGateStep::NAME, MaintenanceOnStep::NAME,
                'update:plugin:wpup-contract-plugin', 'post-update', MaintenanceOffStep::NAME, SmokeCheckStep::NAME, MaintenanceRestoreStep::NAME,
            ])
            ->and(app(RestorePointGate::class)->isVerified($run))->toBeTrue()
            ->and($driver->installedComponents()->find(ComponentType::Plugin, 'wpup-contract-plugin')?->version)->toBe('1.1.0');
    });
});

it('rolls wp83 back, files and database, when the update leaves the wrong version', function (): void {
    inPlaceFixtureUpdate('1.0.5', static function (Run $run, SiteDriver $driver): void {
        $recovery = $run->steps()->where('kind', RunStepKind::Recovery->value)->orderBy('sequence')->get();

        expect($run->status)->toBe(RunStatus::RolledBack, (string) $run->failureReason())
            ->and($run->result_summary['failed_step'] ?? null)->toBe('update:plugin:wpup-contract-plugin')
            ->and($recovery->pluck('name')->all())->toBe([MaintenanceForceOnStep::NAME, FilesRollbackStep::NAME, DatabaseRollbackStep::NAME, MaintenanceRestoreStep::NAME])
            ->and($recovery->pluck('status')->map->value->all())->toBe(['succeeded', 'succeeded', 'succeeded', 'succeeded'])
            ->and($recovery->get(2)?->result['restored'] ?? null)->toBeTrue()
            ->and($driver->installedComponents()->find(ComponentType::Plugin, 'wpup-contract-plugin')?->version)->toBe('1.0.0')
            ->and($driver->wp(['option', 'get', 'blogname'])->successful())->toBeTrue();
    });
});
