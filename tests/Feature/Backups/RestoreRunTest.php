<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetException;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\FilesBackup;
use App\Domain\Backups\FlysystemTarget;
use App\Domain\Backups\NewWritesDetector;
use App\Domain\Backups\OrderWatermark;
use App\Domain\Backups\PreRestoreBackupStep;
use App\Domain\Backups\RestoreCheckStep;
use App\Domain\Backups\RestoreMaintenanceStep;
use App\Domain\Backups\RestorePoint;
use App\Domain\Backups\RestorePointGate;
use App\Domain\Backups\RestorePointRetention;
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\RestoreRefused;
use App\Domain\Backups\RestoreRequest;
use App\Domain\Backups\RestoreSelection;
use App\Domain\Backups\RestoreStep;
use App\Domain\Backups\RetentionHold;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Backups\SiteRestore;
use App\Domain\Backups\StagingCopy;
use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Inventory\SiteInventory;
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
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\Backup;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Run;
use App\Models\RunStep;
use App\Models\Site;
use App\Models\SiteProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Tests\Support\FakeDriver;

/*
 * `backup:restore` runs (UZEROWPUP-46) over FakeDriver: the copies are checked
 * before anything is written, a verified point of the current state comes
 * first, and a shop's database needs --accept-data-loss.
 */

/** A FakeDriver whose archive step can fail or alter the source point. */
final class RestoreRunDriver extends FakeDriver
{
    public ?Throwable $archiveFailure = null;

    /** Changes these stored objects when the next archive is made (between the check and the restore). */
    public ?Closure $onArchive = null;

    /** WP-CLI stops working once the files are restored. */
    public bool $breakAfterRestore = false;

    /** @var list<bool> the maintenance mode when each restore started */
    public array $maintenanceAtRestore = [];

    public function restoreFiles(RemoteFile $archive): void
    {
        $this->maintenanceAtRestore[] = $this->maintenance;
        parent::restoreFiles($archive);
        if ($this->breakAfterRestore) {
            $this->failingCommands = ['core version'];
        }
    }

    public function restoreDatabase(RemoteFile $dump): void
    {
        $this->maintenanceAtRestore[] = $this->maintenance;
        parent::restoreDatabase($dump);
    }

    public function archiveFiles(string $remotePath, array $excludes): RemoteFile
    {
        if ($this->archiveFailure !== null) {
            throw $this->archiveFailure;
        }
        if ($this->onArchive !== null) {
            ($this->onArchive)($this);
        }

        return parent::archiveFiles($remotePath, $excludes);
    }
}

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    config(['wpup.runs.heartbeat_seconds' => 1]);
    Http::fake(['*' => Http::response('', 500)]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** The site-local store and the run pipeline, both served by $driver. */
function restoreRunPipeline(FakeDriver $driver): void
{
    $driverFor = static fn (Site $s, AuditActor $a): SiteDriver => $driver;
    app(BackupTargetRegistry::class)->register(BackupTargetType::SiteLocal, static fn (): BackupTarget => new SiteLocalTarget(
        $driver, app(SecretRedactor::class), app(HttpFactory::class), app(SiteLocalSettings::class), 'https://fake.test',
    ));
    app(PipelineResolver::class)->register(RunType::Restore, static function () use ($driverFor): Pipeline {
        $maintenance = new RestoreMaintenanceStep($driverFor, app(RunAuthorization::class), app(SecretRedactor::class), 60);

        return new Pipeline([
            new InventoryStep(app(SiteInventory::class), $driverFor, app(RunAuthorization::class), 60, readOnlySite: true, advisory: true),
            new RestoreCheckStep(app(RestoreSelection::class), app(SiteRestore::class), app(FilesBackup::class), app(NewWritesDetector::class), $driverFor, app(RunAuthorization::class), 60),
            $maintenance,
            new PreRestoreBackupStep(app(RestorePointService::class), $maintenance, $driverFor, app(RunAuthorization::class), 60),
            new RestoreStep(app(RestoreSelection::class), app(SiteRestore::class), app(RestorePointGate::class), $maintenance, $driverFor,
                app(RunAuthorization::class), app(AuditLogger::class), app(SecretRedactor::class), 60),
        ]);
    });
}

function restoreRunSite(string $name = 'botiga'): Site
{
    $site = Site::factory()->create(['name' => $name]);
    SiteProfile::factory()->create(['site_id' => $site->id]);
    app(SiteLocalDestination::class)->attach($site);

    return $site;
}

/** A manual restore point of the site's current state. */
function restoreRunPoint(Site $site, FakeDriver $driver): RestorePoint
{
    return app(RestorePointService::class)->create($site, $driver, null, AuditActor::system('tests'));
}

/**
 * A restore run queued by an operator.
 *
 * @param  list<BackupKind>  $kinds
 * @param  array<string, mixed>  $flags
 */
function restoreRun(Site $site, RestorePoint $point, array $kinds, bool $acceptDataLoss = false, array $flags = [], ?bool $confirmed = null, bool $withoutPreBackup = false): Run
{
    $user = User::factory()->create(['role' => UserRole::Operator]);
    $request = app(RestoreSelection::class)->select($site, $point->directory, [], $kinds, $acceptDataLoss)->with($confirmed ?? $acceptDataLoss, $withoutPreBackup);

    return Run::factory()->create([
        'type' => RunType::Restore, 'site_id' => $site->id, 'user_id' => $user->id, 'actor' => $user->name,
        'channel' => AuditChannel::Cli, 'flags' => $request->flags() + $flags,
    ]);
}

function restoreStepOf(Run $run, string $name): RunStep
{
    return $run->steps()->where('name', $name)->firstOrFail();
}

it('registers the restore pipeline: inventory, check, maintenance, a point of the current state, then the restore', function (): void {
    $pipeline = app(PipelineResolver::class)->resolve(Run::factory()->create(['type' => RunType::Restore]));

    expect(array_map(fn ($s): string => $s->name(), $pipeline->steps))->toBe([InventoryStep::NAME, RestoreCheckStep::NAME, RestoreMaintenanceStep::NAME, PreRestoreBackupStep::NAME, RestoreStep::NAME])
        ->and($pipeline->get(PreRestoreBackupStep::NAME))->toBeInstanceOf(PreRestoreBackupStep::class);
});

it('restores the files and the database of a point, after a verified point of the current state, and audits it', function (): void {
    $site = restoreRunSite();
    $driver = new RestoreRunDriver;
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);
    $driver->options['blogname'] = 'Canviat';
    $driver->coreVersion = '6.9.0';
    $driver->files['restore-0123456789abcdef.sql'] = 'resta d\'una restauració interrompuda';

    $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Files, BackupKind::Database], acceptDataLoss: true));

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($driver->options['blogname'])->toBe('Botiga de prova')
        ->and($driver->coreVersion)->toBe('6.8.3')
        ->and(app(RestorePointGate::class)->isVerified($run))->toBeTrue()
        ->and(array_values(array_intersect($driver->writeCalls, ['dumpDatabase', 'archiveFiles', 'restoreFiles', 'restoreDatabase'])))
        ->toBe(['dumpDatabase', 'archiveFiles', 'dumpDatabase', 'archiveFiles', 'restoreFiles', 'restoreDatabase'])
        ->and($driver->wpCalls)->toContain(['cache', 'flush'])
        ->and($driver->maintenanceAtRestore)->toBe([true, true])
        ->and($driver->maintenance)->toBeFalse()
        ->and($driver->files)->not->toHaveKey('restore-0123456789abcdef.sql')
        ->and(restoreStepOf($run, RestoreStep::NAME)->summary)->toContain("Punt de l'estat d'abans: «site-{$site->id}/run{$run->id}»")
        ->and(restoreStepOf($run, RestoreStep::NAME)->output)->toContain("--point=run{$run->id} --files --db --accept-data-loss");

    // The pre-restore point holds the changed state.
    $pre = Backup::query()->where('run_id', $run->id)->where('kind', BackupKind::Database)->firstOrFail();
    expect($driver->files["restore-points/{$pre->path}"] ?? '')->toContain('Canviat');

    $audit = AuditLogEntry::query()->where('action', 'backup.restore')->sole();
    expect($audit->result)->toBe(AuditResult::Success)
        ->and($audit->context)->toMatchArray([
            'run_id' => $run->id, 'restore_point' => $point->directory, 'kinds' => ['files', 'database'], 'restored' => ['files', 'database'],
            'pre_restore_point' => "site-{$site->id}/run{$run->id}", 'accepted_data_loss' => true, 'data_loss_confirmed' => true, 'shop' => true,
            'without_pre_backup' => false,
        ]);
});

it('restores only what is asked', function (): void {
    $site = restoreRunSite();
    $driver = new FakeDriver;
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);
    $driver->options['blogname'] = 'Canviat';
    $driver->coreVersion = '6.9.0';

    $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Files]));

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($driver->coreVersion)->toBe('6.8.3')
        ->and($driver->options['blogname'])->toBe('Canviat')
        ->and($driver->writeCalls)->not->toContain('restoreDatabase');
});

it('refuses a shop database without --accept-data-loss before writing anything', function (): void {
    $site = restoreRunSite();
    $driver = new FakeDriver;
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);
    $driver->writeCalls = [];

    $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Database]));

    expect($run->status)->toBe(RunStatus::Failed)
        ->and(restoreStepOf($run, RestoreCheckStep::NAME)->error)->toContain('botiga')->toContain('--accept-data-loss')->toContain('--force no ho salta')
        ->and($driver->writeCalls)->not->toContain('dumpDatabase', 'restoreDatabase')
        ->and(Backup::query()->where('run_id', $run->id)->count())->toBe(0);
});

it('tells which orders and users were written since the copy when it refuses a shop database (UZEROWPUP-58)', function (): void {
    $site = restoreRunSite();
    $driver = new FakeDriver;
    $driver->orders = [['id' => 7, 'type' => 'shop_order', 'status' => 'wc-processing', 'created' => '2026-11-01 10:00:00', 'modified' => '2026-11-01 10:00:00']];
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);
    expect(OrderWatermark::fromArray($point->local(BackupKind::Database)?->order_watermark)?->tables['wc_orders']->maxId)->toBe(7);

    $driver->orders[0]['modified'] = '2026-11-02 09:00:00';
    $driver->orders[] = ['id' => 8, 'type' => 'shop_order', 'status' => 'wc-on-hold', 'created' => '2026-11-02 09:10:00', 'modified' => '2026-11-02 09:10:00'];
    $driver->users[] = ['id' => 5, 'registered' => '2026-11-02 09:09:00', 'roles' => ['customer']];

    $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Database]));

    expect($run->status)->toBe(RunStatus::Failed)
        ->and(restoreStepOf($run, RestoreCheckStep::NAME)->error)->toContain('--accept-data-loss')
        ->toContain('2 comandes: #8 (nova, comanda, on-hold, 2026-11-02 09:10:00), #7 (modificada, comanda, processing, 2026-11-02 09:00:00)')
        ->toContain('1 usuari: #5 (nou, customer')
        ->and($driver->writeCalls)->not->toContain('restoreDatabase');
});

it('keeps asking for the confirmation when nothing was written, and treats a copy without watermark as written', function (bool $withWatermark, string $message): void {
    $site = restoreRunSite();
    $driver = new FakeDriver;
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);
    if (! $withWatermark) {
        Backup::query()->whereKey($point->local(BackupKind::Database)?->id)->update(['order_watermark' => null]);
    }

    $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Database]));

    // Restoring without the confirmation when nothing was written is UZEROWPUP-59.
    expect($run->status)->toBe(RunStatus::Failed)
        ->and(restoreStepOf($run, RestoreCheckStep::NAME)->error)->toContain('--accept-data-loss')->toContain($message)
        ->and($driver->writeCalls)->not->toContain('restoreDatabase');
})->with([
    'nothing written' => [true, 'No hi ha escriptures noves a les comandes ni als usuaris des de la còpia.'],
    'no watermark' => [false, "No es pot saber si hi ha escriptures noves a les comandes i als usuaris des de la còpia (la còpia no té marca d'aigua de comandes i usuaris): es tracta com si n'hi hagués."],
]);

it('records what an accepted restore of a shop database loses', function (): void {
    $site = restoreRunSite();
    $driver = new FakeDriver;
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);
    $driver->orders[] = ['id' => 3, 'type' => 'shop_order', 'status' => 'wc-pending', 'created' => '2026-11-02 09:10:00', 'modified' => '2026-11-02 09:10:00'];

    $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Database], acceptDataLoss: true));
    $check = restoreStepOf($run, RestoreCheckStep::NAME);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($driver->orders)->toBe([])
        ->and($check->output)->toContain('Restauració acceptada amb pèrdua de dades. Des de la còpia hi ha escriptures noves: 1 comanda: #3 (nova')
        ->and(data_get($check->result, 'new_writes.status'))->toBe('found')
        ->and(data_get($check->result, 'new_writes.orders.0.id'))->toBe(3);
});

it('restores the database of a site without WooCommerce without the extra flag', function (): void {
    $site = restoreRunSite();
    $driver = new FakeDriver;
    unset($driver->components['woocommerce:woocommerce']);
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);
    $driver->options['blogname'] = 'Canviat';

    $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Database]));

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($driver->options['blogname'])->toBe('Botiga de prova')
        ->and(AuditLogEntry::query()->where('action', 'backup.restore')->sole()->context['accepted_data_loss'] ?? null)->toBeFalse();
});

it('aborts before touching the site when a stored copy no longer matches its checksum', function (): void {
    $site = restoreRunSite();
    $driver = new FakeDriver;
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);
    $files = $point->local(BackupKind::Files);
    $driver->files["restore-points/{$files?->path}"] = 'altres bytes';
    $driver->writeCalls = [];

    $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Files]));

    expect($run->status)->toBe(RunStatus::Failed)
        ->and(restoreStepOf($run, RestoreCheckStep::NAME)->error)->toContain('ja no té el SHA-256 registrat')
        ->and($driver->writeCalls)->not->toContain('dumpDatabase', 'restoreFiles');
});

it('restores nothing when a copy changes after the check', function (): void {
    $site = restoreRunSite();
    $driver = new RestoreRunDriver;
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);
    $key = 'restore-points/'.$point->local(BackupKind::Files)?->path;
    $driver->onArchive = static function (FakeDriver $d) use ($key): void {
        $d->files[$key] = 'alterat';
    };
    $driver->coreVersion = '6.9.0';

    $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Files]));

    expect($run->status)->toBe(RunStatus::Failed)
        ->and(restoreStepOf($run, RestoreStep::NAME)->error)->toContain("No s'ha restaurat res")
        ->and(str_contains((string) restoreStepOf($run, RestoreStep::NAME)->error, 'Per tornar'))->toBeFalse()
        ->and($driver->writeCalls)->not->toContain('restoreFiles')
        ->and($driver->coreVersion)->toBe('6.9.0')
        ->and(AuditLogEntry::query()->where('action', 'backup.restore')->sole()->result)->toBe(AuditResult::Failure);
});

it('does not restore when the point of the current state cannot be made', function (): void {
    $site = restoreRunSite();
    $driver = new RestoreRunDriver;
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);
    $driver->archiveFailure = new RemoteCommandFailed('tar ha fallat');

    $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Files]));

    expect($run->status)->toBe(RunStatus::Failed)
        ->and(restoreStepOf($run, PreRestoreBackupStep::NAME)->status)->toBe(RunStepStatus::Failed)
        ->and(restoreStepOf($run, RestoreStep::NAME)->status)->toBe(RunStepStatus::Pending)
        ->and($driver->writeCalls)->not->toContain('restoreFiles')
        ->and($driver->maintenance)->toBeFalse();
});

it('reports a site that does not answer after the restore, without undoing it, and says how to go back', function (): void {
    $site = restoreRunSite();
    $driver = new RestoreRunDriver;
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);
    $driver->coreVersion = '6.9.0';
    $driver->breakAfterRestore = true;

    $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Files]));
    $error = (string) restoreStepOf($run, RestoreStep::NAME)->error;

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($error)->toContain('el lloc no respon bé')->toContain('Ja s\'han restaurat els fitxers')
        ->toContain("wpup backup:restore botiga --point=run{$run->id} --files --db")
        ->and($driver->coreVersion)->toBe('6.8.3');
});

it('only checks in a dry run, and changes nothing', function (): void {
    $site = restoreRunSite();
    $driver = new FakeDriver;
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);
    $driver->writeCalls = [];

    $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Files, BackupKind::Database], true, ['dry_run' => true]));

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and(restoreStepOf($run, RestoreCheckStep::NAME)->status)->toBe(RunStepStatus::Succeeded)
        ->and(restoreStepOf($run, RestoreStep::NAME)->summary)->toStartWith('[dry-run] Restaurarà els fitxers i la base de dades del lloc botiga')
        ->and($driver->writeCalls)->toBe([])
        ->and(AuditLogEntry::query()->where('action', 'backup.restore')->count())->toBe(0);
});

it('never restores for a run without an operator', function (): void {
    $site = restoreRunSite();
    $driver = new FakeDriver;
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);
    $run = restoreRun($site, $point, [BackupKind::Files]);
    $run->update(['user_id' => null, 'channel' => AuditChannel::System]);

    $run = app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and(restoreStepOf($run, RestoreCheckStep::NAME)->error)->toContain("l'ha de llançar un operador")
        ->and($driver->writeCalls)->not->toContain('restoreFiles');
});

it('restores from an external destination when the site no longer holds the copy', function (): void {
    $site = restoreRunSite();
    $driver = new FakeDriver;
    restoreRunPipeline($driver);
    $dir = sys_get_temp_dir().'/wpup-restore-run-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $record = BackupTargetRecord::factory()->create(['type' => BackupTargetType::Sftp, 'name' => 'extern']);
    $site->backupTargets()->attach($record->id);
    app(BackupTargetRegistry::class)->register(BackupTargetType::Sftp, static fn (): BackupTarget => new FlysystemTarget(new Filesystem(new LocalFilesystemAdapter($dir)), app(SecretRedactor::class), 1_048_576));

    try {
        $point = restoreRunPoint($site, $driver);
        $local = $point->local(BackupKind::Files);
        unset($driver->files["restore-points/{$local?->path}"]);
        $local?->update(['deleted_at' => now()]);
        $driver->coreVersion = '6.9.0';

        $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Files]));

        expect($run->status)->toBe(RunStatus::Succeeded)
            ->and($driver->coreVersion)->toBe('6.8.3')
            ->and(restoreStepOf($run, RestoreCheckStep::NAME)->summary)->toContain('(extern)')
            ->and(array_filter(array_keys($driver->files), static fn (string $f): bool => str_starts_with($f, 'restore-') && ! str_contains($f, '/')))->toBe([]);
    } finally {
        File::deleteDirectory($dir);
    }
});

it('holds the source point while the restore run is unfinished, even when it is expired', function (): void {
    $site = restoreRunSite();
    $driver = new FakeDriver;
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);
    Backup::query()->whereIn('id', array_map(static fn (Backup $b): int => $b->id, $point->backups))->update(['expires_at' => now()->subDay()]);
    $run = restoreRun($site, $point, [BackupKind::Files]);

    $held = app(RestorePointRetention::class)->plan([$site->id]);
    expect($held->purges)->toBe([])
        ->and($held->held[0]->hold ?? null)->toBe(RetentionHold::NeededByRun);

    $run->update(['status' => RunStatus::Succeeded]);
    expect(app(RestorePointRetention::class)->plan([$site->id])->purges)->toHaveCount(1);
});

it('makes the point of the current state with what the restored archive can overwrite: uploads too', function (): void {
    $site = restoreRunSite();
    $driver = new FakeDriver;
    restoreRunPipeline($driver);
    $site->profile?->update(['include_uploads' => true]);
    $point = restoreRunPoint($site->refresh(), $driver);
    $site->profile?->update(['include_uploads' => false]);

    $run = app(RunEngine::class)->execute(restoreRun($site->refresh(), $point, [BackupKind::Files]));
    $pre = Backup::query()->where('run_id', $run->id)->where('kind', BackupKind::Files)->firstOrFail();
    $archive = unserialize($driver->files["restore-points/{$pre->path}"], ['allowed_classes' => false]);
    $excludes = is_array($archive) ? $archive['excludes'] ?? null : null;

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($excludes)->toBe($driver->archiveExcludes(new RemoteFile('restore-points/'.$point->local(BackupKind::Files)?->path, 0, '')))
        ->and($excludes)->not->toContain('wp-content/uploads');
});

it('uses a verified external copy of the point when the site-local one no longer holds its bytes, with a warning', function (): void {
    $site = restoreRunSite();
    $driver = new FakeDriver;
    restoreRunPipeline($driver);
    $dir = sys_get_temp_dir().'/wpup-restore-run-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $record = BackupTargetRecord::factory()->create(['type' => BackupTargetType::Sftp, 'name' => 'extern']);
    $site->backupTargets()->attach($record->id);
    app(BackupTargetRegistry::class)->register(BackupTargetType::Sftp, static fn (): BackupTarget => new FlysystemTarget(new Filesystem(new LocalFilesystemAdapter($dir)), app(SecretRedactor::class), 1_048_576));

    try {
        $point = restoreRunPoint($site, $driver);
        $driver->files['restore-points/'.$point->local(BackupKind::Files)?->path] = 'malmès';
        $driver->coreVersion = '6.9.0';

        $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Files]));
        $check = restoreStepOf($run, RestoreCheckStep::NAME);

        expect($run->status)->toBe(RunStatus::Succeeded)
            ->and($driver->coreVersion)->toBe('6.8.3')
            ->and($check->summary)->toContain('(extern)')->toContain('1 avisos')
            ->and($check->output)->toContain('ja no té el SHA-256 registrat')->toContain('«extern»');
    } finally {
        File::deleteDirectory($dir);
    }
});

it('leaves the maintenance mode on when it already was', function (): void {
    $site = restoreRunSite();
    $driver = new RestoreRunDriver;
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);
    $driver->maintenance = true;

    $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Files]));

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($driver->maintenanceAtRestore)->toBe([true])
        ->and($driver->maintenance)->toBeTrue();
});

it('restores a site too broken to back up only with --without-pre-backup, and says there is no way back', function (): void {
    $site = restoreRunSite();
    $driver = new RestoreRunDriver;
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);
    $driver->archiveFailure = new RemoteCommandFailed('tar ha fallat');
    $driver->installedFailure = new RemoteCommandFailed('PHP Fatal error');
    $driver->coreVersion = '6.9.0';

    $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Files], withoutPreBackup: true));

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and(restoreStepOf($run, InventoryStep::NAME)->summary)->toContain('Es continua sense inventari')
        ->and(restoreStepOf($run, PreRestoreBackupStep::NAME)->summary)->toContain('--without-pre-backup')
        ->and(restoreStepOf($run, RestoreStep::NAME)->summary)->toContain("Sense punt de l'estat d'abans")
        ->and($driver->coreVersion)->toBe('6.8.3')
        ->and($driver->maintenance)->toBeFalse()
        ->and(AuditLogEntry::query()->where('action', 'backup.restore')->sole()->context)->toMatchArray(['without_pre_backup' => true, 'pre_restore_point' => null]);
});

it('treats a site whose inventory fails as a shop: its database needs the confirmation', function (): void {
    $site = restoreRunSite();
    $driver = new FakeDriver;
    unset($driver->components['woocommerce:woocommerce']);
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);
    $driver->installedFailure = new RemoteCommandFailed('PHP Fatal error');

    $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Database]));

    expect($run->status)->toBe(RunStatus::Failed)
        ->and(restoreStepOf($run, RestoreCheckStep::NAME)->error)->toContain('--accept-data-loss')
        ->and($driver->writeCalls)->not->toContain('restoreDatabase');
});

it('never restores a shop database with --accept-data-loss alone, without the interactive confirmation', function (): void {
    $site = restoreRunSite();
    $driver = new FakeDriver;
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);

    $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Database], acceptDataLoss: true, confirmed: false));

    expect($run->status)->toBe(RunStatus::Failed)
        ->and(restoreStepOf($run, RestoreCheckStep::NAME)->error)->toContain('escrivint el nom del lloc')
        ->and($driver->writeCalls)->not->toContain('restoreDatabase');
});

/** A stream that never ends and never gives a byte. */
final class StuckStream
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return $offset === 0;
    }

    public function stream_tell(): int
    {
        return 0;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}

it('stops a staging copy on a short read or a stuck stream, and leaves no file', function (): void {
    $staging = config()->string('wpup.backups.staging_dir');
    $before = glob($staging.'/copy-*') ?: [];
    $short = fopen('php://temp', 'r+b') ?: throw new RuntimeException('php://temp');
    fwrite($short, 'abc');
    stream_wrapper_register('wpup-stuck', StuckStream::class);

    try {
        $stuck = fopen('wpup-stuck://x', 'rb') ?: throw new RuntimeException('wpup-stuck');
        expect(fn () => StagingCopy::copy($short, $staging, 2, 'La lectura', 'k', 10))->toThrow(BackupTargetException::class, 'lectura incompleta (3 de 10 bytes)')
            ->and(fn () => StagingCopy::copy($stuck, $staging, 2, 'La lectura', 'k'))->toThrow(BackupTargetException::class, 'ha deixat de donar dades')
            ->and(glob($staging.'/copy-*') ?: [])->toBe($before)
            ->and(StagingCopy::copy($short, $staging, 2, 'La lectura', 'k', 3)[1])->toBe(hash('sha256', 'abc'));
    } finally {
        stream_wrapper_unregister('wpup-stuck');
        array_map(unlink(...), array_diff(glob($staging.'/copy-*') ?: [], $before));
    }
});

describe('selection', function (): void {
    it('takes the verified site-local copies of a point, by its directory, its last segment or its run id', function (): void {
        $site = restoreRunSite();
        $driver = new FakeDriver;
        restoreRunPipeline($driver);
        $point = restoreRunPoint($site, $driver);
        $selection = app(RestoreSelection::class);

        foreach ([$point->directory, basename($point->directory)] as $ref) {
            expect($selection->select($site, $ref, [], [BackupKind::Database, BackupKind::Files], false)->candidates)->toBe([
                'database' => [$point->local(BackupKind::Database)?->id],
                'files' => [$point->local(BackupKind::Files)?->id],
            ]);
        }
        $run = Run::factory()->create(['site_id' => $site->id]);
        Backup::factory()->create(['site_id' => $site->id, 'run_id' => $run->id, 'backup_target_id' => $point->localTargetId, 'kind' => BackupKind::Files,
            'path' => "site-{$site->id}/run{$run->id}/files.tar.gz", 'verified_at' => now()]);
        expect($selection->select($site, (string) $run->id, [], [BackupKind::Files], false)->directory)->toBe("site-{$site->id}/run{$run->id}");
    });

    it('refuses what cannot be restored', function (Closure $select, string $message): void {
        $site = restoreRunSite();
        $driver = new FakeDriver;
        restoreRunPipeline($driver);
        $point = restoreRunPoint($site, $driver);
        $other = restoreRunSite('altre');
        $otherPoint = restoreRunPoint($other, $driver);

        expect(fn () => $select(app(RestoreSelection::class), $site, $point, $otherPoint))->toThrow(RestoreRefused::class, $message);
    })->with([
        'nothing to restore' => [fn (RestoreSelection $s, Site $site, RestorePoint $p) => $s->select($site, $p->directory, [], [], false), '--files, --db'],
        'point and rows' => [fn (RestoreSelection $s, Site $site, RestorePoint $p) => $s->select($site, $p->directory, [1], [BackupKind::Files], false), 'només una de les dues'],
        'point of another site' => [fn (RestoreSelection $s, Site $site, RestorePoint $p, RestorePoint $o) => $s->select($site, $o->directory, [], [BackupKind::Files], false), 'no és un punt de restauració del lloc'],
        'row of another site' => [fn (RestoreSelection $s, Site $site, RestorePoint $p, RestorePoint $o) => $s->select($site, null, [(int) $o->local(BackupKind::Files)?->id], [BackupKind::Files], false), "és d'un altre lloc"],
        'unknown point' => [fn (RestoreSelection $s, Site $site) => $s->select($site, 'run999999', [], [BackupKind::Files], false), 'no té cap còpia dels fitxers viva'],
        'unverified' => [function (RestoreSelection $s, Site $site, RestorePoint $p) {
            $p->local(BackupKind::Files)?->update(['verified_at' => null]);

            return $s->select($site, $p->directory, [], [BackupKind::Files], false);
        }, 'no està verificada'],
        'unverified row' => [function (RestoreSelection $s, Site $site, RestorePoint $p) {
            $p->local(BackupKind::Files)?->update(['verified_at' => null]);

            return $s->select($site, null, [(int) $p->local(BackupKind::Files)?->id], [BackupKind::Files], false);
        }, 'no està verificada'],
        'deleted row' => [function (RestoreSelection $s, Site $site, RestorePoint $p) {
            $p->local(BackupKind::Files)?->update(['deleted_at' => now()]);

            return $s->select($site, null, [(int) $p->local(BackupKind::Files)?->id], [BackupKind::Files], false);
        }, "s'ha esborrat"],
        'kind not asked' => [fn (RestoreSelection $s, Site $site, RestorePoint $p) => $s->select($site, null, [(int) $p->local(BackupKind::Database)?->id], [BackupKind::Files], false), "no l'has demanat (--db)"],
        'kind missing' => [fn (RestoreSelection $s, Site $site, RestorePoint $p) => $s->select($site, null, [(int) $p->local(BackupKind::Database)?->id], [BackupKind::Files, BackupKind::Database], false), 'Falta la còpia dels fitxers'],
    ]);

    it('checks the rows of a run again, and refuses a request it cannot read', function (): void {
        $site = restoreRunSite();
        $driver = new FakeDriver;
        restoreRunPipeline($driver);
        $point = restoreRunPoint($site, $driver);
        $request = app(RestoreSelection::class)->select($site, $point->directory, [], [BackupKind::Files], false);
        $point->local(BackupKind::Files)?->update(['deleted_at' => now()]);

        expect(fn () => app(RestoreSelection::class)->rows($site, $request, ['files' => (int) $point->local(BackupKind::Files)?->id]))->toThrow(RestoreRefused::class, "s'ha esborrat")
            ->and(fn () => app(RestoreSelection::class)->candidates($site, $request))->toThrow(RestoreRefused::class, "s'ha esborrat")
            ->and(fn () => app(RestoreSelection::class)->rows($site, $request, ['files' => 999999]))->toThrow(RestoreRefused::class, 'No s\'ha triat cap còpia vàlida')
            ->and(fn () => RestoreRequest::fromRun(Run::factory()->create(['flags' => [RestoreRequest::POINT_FLAG => 'x', RestoreRequest::BACKUPS_FLAG => ['files' => ['1']]]])))
            ->toThrow(RestoreRefused::class);
    });
});
