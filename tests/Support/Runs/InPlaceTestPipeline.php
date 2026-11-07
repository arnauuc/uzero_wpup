<?php

declare(strict_types=1);

namespace Tests\Support\Runs;

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
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\RestoreSelection;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Backups\SiteRestore;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Maintenance\MaintenanceGuard;
use App\Domain\Maintenance\MaintenanceSteps;
use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Planning\PlanStep;
use App\Domain\Planning\UpdatePlan;
use App\Domain\Rollback\RevertedChanges;
use App\Domain\Rollback\RollbackSteps;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Updates\InPlaceUpdatePipeline;
use App\Domain\Updates\PostUpdateSettings;
use App\Domain\Updates\SmokeCheckSettings;
use App\Domain\Updates\SmokeCheckSteps;
use App\Domain\Updates\UpdateChanges;
use App\Domain\Updates\UpdateSteps;
use App\Enums\BackupTargetType;
use App\Enums\PhpVersionSource;
use App\Enums\RunType;
use App\Models\Run;
use App\Models\Site;
use Illuminate\Http\Client\Factory as HttpFactory;
use LogicException;
use Tests\Support\FakeDriver;

/**
 * The update pipeline of UZEROWPUP-60 over a FakeDriver, for the feature tests
 * (InPlaceUpdateRunTest) and the crash tests (tests/Process): real backup,
 * gate, update, smoke check, maintenance and rollback steps; the inventory and
 * the plan scripted (the plan as PlanStep stores it, with the artifacts its
 * analysis would have fetched).
 */
final class InPlaceTestPipeline
{
    /**
     * @param  list<PlannedChange>  $changes
     */
    public static function register(FakeDriver $driver, array $changes): void
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
}
