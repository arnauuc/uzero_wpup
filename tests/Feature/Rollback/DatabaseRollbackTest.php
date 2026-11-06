<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\NewWritesDetector;
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\RestoreSelection;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Backups\SiteRestore;
use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Rollback\DatabaseRollbackStep;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\RunEngine;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\AuditChannel;
use App\Enums\AuditResult;
use App\Enums\BackupKind;
use App\Enums\BackupTargetType;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
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

/*
 * Database rollback of an update run (UZEROWPUP-59, AGENTS.md rule 4) over
 * FakeDriver: the database of the run's point is put back only when nothing
 * was written to the orders or users since the copy; otherwise it is never
 * touched and the operator is told which orders and users.
 */

/** A FakeDriver that records the maintenance mode at the restore and can change the site when it is switched on. */
final class DatabaseRollbackDriver extends FakeDriver
{
    /** @var list<bool> */
    public array $maintenanceAtRestore = [];

    public ?Closure $onMaintenance = null;

    public bool $failRestore = false;

    public bool $maintenanceFails = false;

    public function setMaintenanceMode(bool $enabled): void
    {
        if ($this->maintenanceFails) {
            throw new RemoteCommandFailed('maintenance-mode ha fallat');
        }
        parent::setMaintenanceMode($enabled);
        if ($enabled && $this->onMaintenance !== null) {
            ($this->onMaintenance)($this);
        }
    }

    public function restoreDatabase(RemoteFile $dump): void
    {
        $this->maintenanceAtRestore[] = $this->maintenance;
        if ($this->failRestore) {
            $this->writeCalls[] = 'restoreDatabase';

            throw new RemoteCommandFailed('wp db import ha fallat a mitges');
        }
        parent::restoreDatabase($dump);
    }
}

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    config(['wpup.runs.heartbeat_seconds' => 1]);
    Http::fake(['*' => Http::response('', 500)]);
});

function databaseRollbackPipeline(FakeDriver $driver): void
{
    app(BackupTargetRegistry::class)->register(BackupTargetType::SiteLocal, static fn (): BackupTarget => new SiteLocalTarget(
        $driver, app(SecretRedactor::class), app(HttpFactory::class), app(SiteLocalSettings::class), 'https://fake.test',
    ));
    app(PipelineResolver::class)->register(RunType::Rollback, static fn (): Pipeline => new Pipeline([new DatabaseRollbackStep(
        app(RestoreSelection::class), app(SiteRestore::class), app(NewWritesDetector::class), static fn (Site $s, AuditActor $a): SiteDriver => $driver,
        app(RunAuthorization::class), app(AuditLogger::class), app(SecretRedactor::class), 60,
    )]));
}

/**
 * An update run with its verified restore point, after which the "update"
 * changes the database (an option).
 *
 * @return array{Site, Run}
 */
function databaseRollbackUpdate(FakeDriver $driver): array
{
    $site = Site::factory()->create(['name' => 'botiga']);
    SiteProfile::factory()->create(['site_id' => $site->id]);
    app(SiteLocalDestination::class)->attach($site);
    $user = User::factory()->create(['role' => UserRole::Operator]);
    $update = Run::factory()->create(['type' => RunType::Update, 'site_id' => $site->id, 'user_id' => $user->id, 'actor' => $user->name,
        'channel' => AuditChannel::Cli, 'status' => RunStatus::Failed]);
    app(RestorePointService::class)->create($site, $driver, $update, AuditActor::system('tests'));
    $driver->options['woocommerce_db_version'] = '10.0.0';

    return [$site, $update];
}

function databaseRollbackRun(Site $site, Run $update): Run
{
    return app(RunEngine::class)->execute(Run::factory()->create(['type' => RunType::Rollback, 'site_id' => $site->id, 'parent_run_id' => $update->id,
        'user_id' => $update->user_id, 'actor' => $update->actor, 'channel' => AuditChannel::Cli]));
}

function databaseRollbackStep(Run $run): RunStep
{
    return $run->steps()->where('name', DatabaseRollbackStep::NAME)->sole();
}

/** The site with a new order since the copy: the copy has no order 12. */
function databaseRollbackNewOrder(FakeDriver $driver): void
{
    $driver->orders[] = ['id' => 12, 'type' => 'shop_order', 'status' => 'wc-processing', 'created' => '2026-11-06 10:00:00', 'modified' => '2026-11-06 10:00:00'];
}

it('restores the database automatically when nothing was written since the copy, with the shop closed', function (): void {
    $driver = new DatabaseRollbackDriver;
    databaseRollbackPipeline($driver);
    [$site, $update] = databaseRollbackUpdate($driver);
    $driver->files['restore-0123456789abcdef.sql'] = 'resta d\'una restauració interrompuda';

    $run = databaseRollbackRun($site, $update);

    $step = databaseRollbackStep($run);
    expect($run->status)->toBe(RunStatus::Succeeded, (string) $step->error)
        ->and($driver->options)->not->toHaveKey('woocommerce_db_version')
        ->and($driver->maintenanceAtRestore)->toBe([true])
        // Switched on by this step, so switched off again.
        ->and($driver->maintenance)->toBeFalse()
        ->and($driver->files)->not->toHaveKey('restore-0123456789abcdef.sql')
        ->and($step->summary)->toContain('Base de dades restaurada')
        ->and($step->result['restored'] ?? null)->toBeTrue()
        ->and(data_get($step->result, 'new_writes.status'))->toBe('none');

    $audit = AuditLogEntry::query()->where('action', 'rollback.database')->sole();
    expect($audit->result)->toBe(AuditResult::Success)
        ->and($audit->context['source_run_id'] ?? null)->toBe($update->id)
        ->and($audit->context['restored'] ?? null)->toBe('yes');
});

it('never restores automatically a shop database with a new order since the copy, and tells which orders', function (): void {
    $driver = new DatabaseRollbackDriver;
    databaseRollbackPipeline($driver);
    [$site, $update] = databaseRollbackUpdate($driver);
    databaseRollbackNewOrder($driver);
    $driver->users[] = ['id' => 9, 'registered' => '2026-11-06 10:01:00', 'roles' => ['customer']];

    $run = databaseRollbackRun($site, $update);

    $step = databaseRollbackStep($run);
    expect($run->status)->toBe(RunStatus::Succeeded, (string) $step->error)
        ->and($driver->writeCalls)->not->toContain('restoreDatabase')
        ->and($driver->writeCalls)->not->toContain('setMaintenanceMode')
        ->and($driver->options['woocommerce_db_version'])->toBe('10.0.0')
        ->and(array_column($driver->orders, 'id'))->toBe([12])
        ->and($step->summary)->toStartWith('BD no restaurada')
        ->and($step->output)->toContain('1 comanda: #12 (nova, comanda, processing, 2026-11-06 10:00:00)')
        ->toContain('1 usuari: #9 (nou, customer')
        ->toContain("Només s'han restaurat els fitxers; la base de dades no s'ha tocat.")
        ->toContain("wpup backup:restore botiga --point=run{$update->id} --db --accept-data-loss")
        ->and($step->result['restored'] ?? null)->toBeFalse()
        ->and(data_get($step->result, 'new_writes.orders.0'))->toBe(['id' => 12, 'new' => true, 'date' => '2026-11-06 10:00:00', 'label' => 'comanda, processing'])
        ->and($step->result['warning'] ?? null)->toContain('#12')
        ->and($step->result['force_command'] ?? null)->toBe("wpup backup:restore botiga --point=run{$update->id} --db --accept-data-loss");

    $audit = AuditLogEntry::query()->where('action', 'rollback.database')->sole();
    expect($audit->result)->toBe(AuditResult::Denied)
        ->and(data_get($audit->context, 'new_writes.orders.0.id'))->toBe(12);
});

it('does not restore when it cannot be known what was written', function (Closure $break, string $reason): void {
    $driver = new DatabaseRollbackDriver;
    databaseRollbackPipeline($driver);
    [$site, $update] = databaseRollbackUpdate($driver);
    $break($driver, $update);

    $run = databaseRollbackRun($site, $update);

    $step = databaseRollbackStep($run);
    expect($run->status)->toBe(RunStatus::Succeeded, (string) $step->error)
        ->and($driver->writeCalls)->not->toContain('restoreDatabase')
        ->and($driver->options['woocommerce_db_version'])->toBe('10.0.0')
        ->and($step->summary)->toStartWith('BD no restaurada')
        ->and($step->output)->toContain($reason)
        ->and(data_get($step->result, 'new_writes.status'))->toBe('unknown');
})->with([
    'no watermark' => [function (FakeDriver $d, Run $update): void {
        Backup::query()->where('run_id', $update->id)->where('kind', BackupKind::Database)->update(['order_watermark' => null]);
    }, "la còpia no té marca d'aigua"],
    'watermark of another version' => [function (FakeDriver $d, Run $update): void {
        Backup::query()->where('run_id', $update->id)->where('kind', BackupKind::Database)->update(['order_watermark' => json_encode(['version' => 99])]);
    }, "la marca d'aigua de la còpia no és vàlida"],
    'site that cannot be read' => [function (FakeDriver $d): void {
        $d->shopWritesOutput = 'Fatal error: res';
    }, "no s'ha pogut llegir el lloc"],
]);

it('checks again right before the restore, with the maintenance mode on, and does not restore an order that entered meanwhile', function (): void {
    $driver = new DatabaseRollbackDriver;
    databaseRollbackPipeline($driver);
    [$site, $update] = databaseRollbackUpdate($driver);
    $driver->onMaintenance = static function (FakeDriver $d): void {
        databaseRollbackNewOrder($d);
    };

    $run = databaseRollbackRun($site, $update);

    $step = databaseRollbackStep($run);
    expect($run->status)->toBe(RunStatus::Succeeded, (string) $step->error)
        ->and($driver->writeCalls)->not->toContain('restoreDatabase')
        ->and(array_column($driver->orders, 'id'))->toBe([12])
        ->and($driver->maintenance)->toBeFalse()
        ->and($step->output)->toContain('#12 (nova');
});

it('leaves the maintenance mode on when it already was (the files rollback keeps it on)', function (): void {
    $driver = new DatabaseRollbackDriver;
    databaseRollbackPipeline($driver);
    [$site, $update] = databaseRollbackUpdate($driver);
    $driver->maintenance = true;

    $run = databaseRollbackRun($site, $update);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($driver->maintenanceAtRestore)->toBe([true])
        ->and($driver->maintenance)->toBeTrue()
        ->and($driver->writeCalls)->not->toContain('setMaintenanceMode');
});

it('does not restore when the maintenance mode cannot be switched on', function (): void {
    $driver = new DatabaseRollbackDriver;
    databaseRollbackPipeline($driver);
    [$site, $update] = databaseRollbackUpdate($driver);
    $driver->maintenanceFails = true;

    $run = databaseRollbackRun($site, $update);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and(databaseRollbackStep($run)->error)->toContain("No s'ha pogut activar el mode manteniment")->toContain("La base de dades no s'ha restaurat.")
        ->and($driver->writeCalls)->not->toContain('restoreDatabase');
});

it('keeps the maintenance mode on and says so when the restore fails half way', function (): void {
    $driver = new DatabaseRollbackDriver;
    databaseRollbackPipeline($driver);
    [$site, $update] = databaseRollbackUpdate($driver);
    $driver->failRestore = true;

    $run = databaseRollbackRun($site, $update);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and(databaseRollbackStep($run)->error)->toContain('pot haver quedat a mitges')->toContain("--point=run{$update->id} --db")
        ->and($driver->maintenance)->toBeTrue()
        ->and(AuditLogEntry::query()->where('action', 'rollback.database')->sole()->context['restored'] ?? null)->toBe('unknown');
});

it('refuses a run that is not the rollback of an update of the same site', function (): void {
    $driver = new DatabaseRollbackDriver;
    databaseRollbackPipeline($driver);
    [$site, $update] = databaseRollbackUpdate($driver);
    $update->update(['type' => RunType::Backup]);

    $run = databaseRollbackRun($site, $update);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and(databaseRollbackStep($run)->error)->toContain('no indica quina actualització')
        ->and($driver->writeCalls)->not->toContain('restoreDatabase');
});
