<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\RestoreMaintenanceStep;
use App\Domain\Backups\RestorePointRetention;
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\RetentionHold;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Rollback\DatabaseRollbackStep;
use App\Domain\Rollback\FilesRollbackStep;
use App\Domain\Rollback\RollbackCheckStep;
use App\Domain\Rollback\RollbackMaintenanceRestoreStep;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Updates\UpdateChanges;
use App\Enums\AuditChannel;
use App\Enums\AuditResult;
use App\Enums\BackupKind;
use App\Enums\BackupTargetType;
use App\Enums\ChangeResult;
use App\Enums\ComponentType;
use App\Enums\RunStatus;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\Backup;
use App\Models\Run;
use App\Models\RunChange;
use App\Models\Site;
use App\Models\SiteProfile;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeDriver;

/*
 * `wpup rollback <run>` (UZEROWPUP-61) over FakeDriver, end to end (the queue
 * is sync in tests): what it refuses before queueing, the rollback run linked
 * to the update, the same new-orders protection as the update's own rollback,
 * and the update's point held by the retention afterwards.
 */

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    config(['wpup.runs.heartbeat_seconds' => 1]);
    Http::fake(['*' => Http::response('', 500)]);
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('oper1');
});

/** The site-local store and the real rollback pipeline, both served by $driver. */
function manualRollbackPipeline(FakeDriver $driver): void
{
    app(BackupTargetRegistry::class)->register(BackupTargetType::SiteLocal, static fn (): BackupTarget => new SiteLocalTarget(
        $driver, app(SecretRedactor::class), app(HttpFactory::class), app(SiteLocalSettings::class), 'https://fake.test',
    ));
    app(PipelineResolver::class)->register(RunType::Rollback, static fn (): Pipeline => AppServiceProvider::manualRollbackPipeline(
        app(), static fn (Site $s, AuditActor $a): SiteDriver => $driver,
    ));
}

/**
 * A succeeded update with its inventory and verified restore point, after
 * which the "update" changed classic-editor and the database (an option).
 */
function manualRollbackUpdate(FakeDriver $driver, string $name = 'botiga'): Run
{
    $site = Site::factory()->create(['name' => $name]);
    SiteProfile::factory()->create(['site_id' => $site->id]);
    app(SiteLocalDestination::class)->attach($site);
    $user = User::query()->where('unix_username', 'oper1')->sole();
    $update = Run::factory()->create(['type' => RunType::Update, 'site_id' => $site->id, 'user_id' => $user->id, 'actor' => $user->name,
        'channel' => AuditChannel::Cli, 'status' => RunStatus::Succeeded, 'finished_at' => now()]);
    app(SiteInventory::class)->take($site, $driver, $update, probeWeb: false);
    app(RestorePointService::class)->create($site, $driver, $update, AuditActor::system('tests'));

    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'classic-editor', '1.7.0'));
    $driver->options['woocommerce_db_version'] = '10.0.0';
    RunChange::factory()->create(['run_id' => $update->id, 'step' => UpdateChanges::STEP, 'component_type' => ComponentType::Plugin, 'slug' => 'classic-editor',
        'version_before' => '1.6.3', 'version_after' => '1.7.0', 'result' => ChangeResult::Updated]);
    $driver->writeCalls = [];

    return $update;
}

function manualRollbackRun(): Run
{
    return Run::query()->where('type', RunType::Rollback)->sole();
}

it('rolls back a succeeded update as a rollback run linked to it: point of the current state, files, database, maintenance as before', function (): void {
    $driver = new FakeDriver;
    manualRollbackPipeline($driver);
    $update = manualRollbackUpdate($driver);

    command('rollback', ['run' => (string) $update->id, '--wait' => true])
        ->expectsOutputToContain("es desfarà l'actualització #{$update->id}")
        ->expectsQuestion("Això torna el codi del lloc botiga a l'estat d'abans de l'actualització #{$update->id}. Escriu el nom del lloc per confirmar-ho", 'botiga')
        ->assertSuccessful();

    $run = manualRollbackRun();
    expect($run->status)->toBe(RunStatus::Succeeded, (string) json_encode($run->result_summary))
        ->and($run->parent_run_id)->toBe($update->id)
        ->and($run->site_id)->toBe($update->site_id)
        ->and($run->steps()->orderBy('id')->pluck('name')->all())->toBe([
            InventoryStep::NAME, RollbackCheckStep::NAME, RestoreMaintenanceStep::NAME, 'backup',
            FilesRollbackStep::NAME, DatabaseRollbackStep::NAME, RollbackMaintenanceRestoreStep::NAME,
        ])
        ->and($driver->components['plugin:classic-editor']->version)->toBe('1.6.3')
        ->and($driver->options)->not->toHaveKey('woocommerce_db_version')
        ->and($driver->maintenance)->toBeFalse()
        // The current state was backed up first (AGENTS.md rule 3).
        ->and(array_search('archiveFiles', $driver->writeCalls, true))->toBeLessThan((int) array_search('restoreFiles', $driver->writeCalls, true))
        ->and(Backup::query()->where('run_id', $run->id)->whereNotNull('verified_at')->get()->map(fn (Backup $b): string => $b->kind->value)->sort()->values()->all())
        ->toBe(['database', 'files'])
        ->and($update->changes()->sole()->result)->toBe(ChangeResult::Reverted)
        ->and($update->fresh()?->reverted_at)->not->toBeNull()
        // The update itself keeps its status: the history is not rewritten.
        ->and($update->fresh()?->status)->toBe(RunStatus::Succeeded);

    $queued = AuditLogEntry::query()->where('action', 'rollback.queued')->sole();
    expect($queued->result)->toBe(AuditResult::Success)
        ->and($queued->context)->toMatchArray(['site' => 'botiga', 'dry_run' => false, 'confirmation' => 'typed'])
        ->and(AuditLogEntry::query()->where('action', 'run.queued')->sole()->context['parent_run_id'] ?? null)->toBe($update->id);
});

it('restores only the files of a shop with a new order since the copy, and says which orders', function (): void {
    $driver = new FakeDriver;
    manualRollbackPipeline($driver);
    $update = manualRollbackUpdate($driver);
    $driver->orders[] = ['id' => 12, 'type' => 'shop_order', 'status' => 'wc-processing', 'created' => '2026-11-06 10:00:00', 'modified' => '2026-11-06 10:00:00'];

    command('rollback', ['run' => (string) $update->id, '--force' => true, '--wait' => true])
        ->expectsOutputToContain('backup:restore')
        ->assertSuccessful();

    $run = manualRollbackRun();
    $database = $run->steps()->where('name', DatabaseRollbackStep::NAME)->sole();
    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($driver->components['plugin:classic-editor']->version)->toBe('1.6.3')
        ->and($driver->options['woocommerce_db_version'] ?? null)->toBe('10.0.0')
        ->and($driver->writeCalls)->not->toContain('restoreDatabase')
        ->and($database->result['restored'] ?? null)->toBeFalse()
        ->and((string) json_encode($database->result))->toContain('12')
        ->and($driver->maintenance)->toBeFalse()
        ->and(AuditLogEntry::query()->where('action', 'rollback.queued')->sole()->context['confirmation'] ?? null)->toBe('force');
});

it('refuses before the site is touched when a copy no longer passes its integrity check at the destination', function (BackupKind $kind): void {
    $driver = new FakeDriver;
    manualRollbackPipeline($driver);
    $update = manualRollbackUpdate($driver);
    $row = Backup::query()->where('run_id', $update->id)->where('kind', $kind)->sole();
    $driver->files["restore-points/{$row->path}"] .= 'alterat';

    $exit = Artisan::call('rollback', ['run' => (string) $update->id, '--force' => true, '--wait' => true]);

    $run = manualRollbackRun();
    $check = $run->steps()->where('name', RollbackCheckStep::NAME)->sole();
    expect($exit)->not->toBe(0)
        ->and($run->status)->toBe(RunStatus::Failed)
        ->and($check->error)->toContain('SHA-256')->toContain("comprovació d'integritat")->toContain("No s'ha tocat el lloc")
        ->and($run->steps()->where('name', FilesRollbackStep::NAME)->first()?->status)->toBeIn([null, RunStepStatus::Pending])
        ->and($driver->writeCalls)->toBe([])
        ->and($driver->maintenance)->toBeFalse()
        ->and($driver->components['plugin:classic-editor']->version)->toBe('1.7.0')
        ->and($update->fresh()?->reverted_at)->toBeNull();
})->with([BackupKind::Files, BackupKind::Database]);

it('runs only the checks with --dry-run, which needs no confirmation', function (): void {
    $driver = new FakeDriver;
    manualRollbackPipeline($driver);
    $update = manualRollbackUpdate($driver);

    $exit = Artisan::call('rollback', ['run' => (string) $update->id, '--dry-run' => true, '--wait' => true, '--no-interaction' => true]);

    $run = manualRollbackRun();
    expect($exit)->toBe(0)
        ->and($run->flags['dry_run'] ?? null)->toBeTrue()
        ->and($run->steps()->where('name', RollbackCheckStep::NAME)->sole()->status->value)->toBe('succeeded')
        ->and($driver->writeCalls)->toBe([])
        ->and($driver->components['plugin:classic-editor']->version)->toBe('1.7.0');
});

it('refuses before queueing: not an update, unfinished, nothing applied, already rolled back', function (Closure $arrange, string $message): void {
    $driver = new FakeDriver;
    manualRollbackPipeline($driver);
    $update = manualRollbackUpdate($driver);
    $id = $arrange($update);
    assert(is_int($id));
    $rollbacks = Run::query()->where('type', RunType::Rollback)->count();

    $exit = Artisan::call('rollback', ['run' => (string) $id, '--force' => true]);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain($message)
        ->and(Run::query()->where('type', RunType::Rollback)->count())->toBe($rollbacks)
        ->and(AuditLogEntry::query()->where('action', 'rollback.refused')->sole()->result)->toBe(AuditResult::Failure);
})->with([
    'not an update' => [fn (Run $u): int => Run::factory()->create(['type' => RunType::Backup, 'site_id' => $u->site_id, 'status' => RunStatus::Succeeded])->id, 'no és una actualització'],
    'unfinished' => [function (Run $u): int {
        $u->update(['status' => RunStatus::Running]);

        return $u->id;
    }, 'encara no ha acabat'],
    'rolled back by itself' => [function (Run $u): int {
        $u->update(['status' => RunStatus::RolledBack]);

        return $u->id;
    }, 'ja es va revertir ella mateixa'],
    'nothing applied' => [function (Run $u): int {
        $u->changes()->update(['result' => ChangeResult::Reverted->value]);

        return $u->id;
    }, 'no va aplicar cap canvi'],
    'rolled back by hand already' => [function (Run $u): int {
        Run::factory()->create(['type' => RunType::Rollback, 'site_id' => $u->site_id, 'parent_run_id' => $u->id, 'status' => RunStatus::Succeeded]);

        return $u->id;
    }, 'ja es va revertir amb el rollback'],
]);

it('refuses while a later update of the site is still applied', function (): void {
    $driver = new FakeDriver;
    manualRollbackPipeline($driver);
    $update = manualRollbackUpdate($driver);
    $later = Run::factory()->create(['type' => RunType::Update, 'site_id' => $update->site_id, 'status' => RunStatus::Succeeded]);
    RunChange::factory()->create(['run_id' => $later->id, 'step' => UpdateChanges::STEP, 'slug' => 'akismet', 'result' => ChangeResult::Updated]);

    $exit = Artisan::call('rollback', ['run' => (string) $update->id, '--force' => true]);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain("l'actualització #{$later->id}")->toContain("Reverteix primer la #{$later->id}")
        ->and(Run::query()->where('type', RunType::Rollback)->count())->toBe(0);
});

it('refuses when a copy of the point has expired, and when it is not verified any more', function (Closure $spoil, string $message): void {
    $driver = new FakeDriver;
    manualRollbackPipeline($driver);
    $update = manualRollbackUpdate($driver);
    $spoil(Backup::query()->where('run_id', $update->id)->where('kind', BackupKind::Database)->sole());

    $exit = Artisan::call('rollback', ['run' => (string) $update->id, '--force' => true]);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain($message)
        ->and(Run::query()->where('type', RunType::Rollback)->count())->toBe(0)
        ->and($driver->writeCalls)->toBe([]);
})->with([
    'expired' => [fn (Backup $b) => $b->update(['expires_at' => now()->subMinute()]), 'va caducar'],
    'not verified' => [fn (Backup $b) => $b->update(['verified_at' => null]), 'no està verificada'],
]);

it('needs --force or --dry-run without a terminal or with --json, and does nothing on a wrong name', function (): void {
    $driver = new FakeDriver;
    manualRollbackPipeline($driver);
    $update = manualRollbackUpdate($driver);

    expect(Artisan::call('rollback', ['run' => (string) $update->id, '--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('cal --dry-run o --force');
    command('rollback', ['run' => (string) $update->id])
        ->expectsQuestion("Això torna el codi del lloc botiga a l'estat d'abans de l'actualització #{$update->id}. Escriu el nom del lloc per confirmar-ho", 'sí')
        ->expectsOutputToContain("No s'ha desfet res.")
        ->assertExitCode(1);

    expect(Run::query()->where('type', RunType::Rollback)->count())->toBe(0)
        ->and(AuditLogEntry::query()->where('action', 'rollback.queued')->count())->toBe(0);
});

it('holds the point of an update undone by hand until it is reviewed, also one that had succeeded and was reviewed before', function (): void {
    $driver = new FakeDriver;
    manualRollbackPipeline($driver);
    $update = manualRollbackUpdate($driver);
    $update->update(['reviewed_at' => now(), 'reviewed_by' => User::query()->value('id')]);
    $directory = "site-{$update->site_id}/run{$update->id}";
    $holds = static function (): array {
        $out = [];
        foreach (app(RestorePointRetention::class)->plan()->held as $held) {
            $out[$held->point->directory] = $held->hold;
        }

        return $out;
    };

    Artisan::call('rollback', ['run' => (string) $update->id, '--force' => true, '--wait' => true]);
    expect(manualRollbackRun()->status)->toBe(RunStatus::Succeeded);
    // Long past the 7 days: only the hold keeps it.
    Backup::query()->update(['expires_at' => now()->subDay()]);

    expect($update->fresh()?->reviewed_at)->toBeNull()
        ->and($holds()[$directory] ?? null)->toBe(RetentionHold::IncidentNotReviewed);

    command('run:ack', ['run' => (string) $update->id])->expectsConfirmation(
        "Marcar-la com a revisada? El seu punt de restauració deixarà d'estar retingut: caducarà d'aquí a ".config()->integer('wpup.backups.retention_days').' dies i seguirà la retenció normal.', 'yes',
    )->assertSuccessful();
    Backup::query()->where('run_id', $update->id)->update(['expires_at' => now()->subDay()]);

    expect($holds())->not->toHaveKey($directory);
});
