<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Backups\BackupStep;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\FlysystemTarget;
use App\Domain\Backups\RestoreCheckStep;
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

    public function restoreFiles(RemoteFile $archive): void
    {
        parent::restoreFiles($archive);
        if ($this->breakAfterRestore) {
            $this->failingCommands = ['core version'];
        }
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
    app(PipelineResolver::class)->register(RunType::Restore, static fn (): Pipeline => new Pipeline([
        new InventoryStep(app(SiteInventory::class), $driverFor, app(RunAuthorization::class), 60),
        new RestoreCheckStep(app(RestoreSelection::class), app(SiteRestore::class), $driverFor, app(RunAuthorization::class), 60),
        new BackupStep(app(RestorePointService::class), $driverFor, app(RunAuthorization::class), 60),
        new RestoreStep(app(RestoreSelection::class), app(SiteRestore::class), app(RestorePointGate::class), $driverFor,
            app(RunAuthorization::class), app(AuditLogger::class), app(SecretRedactor::class), 60),
    ]));
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
function restoreRun(Site $site, RestorePoint $point, array $kinds, bool $acceptDataLoss = false, array $flags = []): Run
{
    $user = User::factory()->create(['role' => UserRole::Operator]);
    $request = app(RestoreSelection::class)->select($site, $point->directory, [], $kinds, $acceptDataLoss);

    return Run::factory()->create([
        'type' => RunType::Restore, 'site_id' => $site->id, 'user_id' => $user->id, 'actor' => $user->name,
        'channel' => AuditChannel::Cli, 'flags' => $request->flags() + $flags,
    ]);
}

function restoreStepOf(Run $run, string $name): RunStep
{
    return $run->steps()->where('name', $name)->firstOrFail();
}

it('registers the restore pipeline: inventory, check, a point of the current state, then the restore', function (): void {
    $pipeline = app(PipelineResolver::class)->resolve(Run::factory()->create(['type' => RunType::Restore]));

    expect(array_map(fn ($s): string => $s->name(), $pipeline->steps))->toBe([InventoryStep::NAME, RestoreCheckStep::NAME, BackupStep::NAME, RestoreStep::NAME]);
});

it('restores the files and the database of a point, after a verified point of the current state, and audits it', function (): void {
    $site = restoreRunSite();
    $driver = new FakeDriver;
    restoreRunPipeline($driver);
    $point = restoreRunPoint($site, $driver);
    $driver->options['blogname'] = 'Canviat';
    $driver->coreVersion = '6.9.0';

    $run = app(RunEngine::class)->execute(restoreRun($site, $point, [BackupKind::Files, BackupKind::Database], acceptDataLoss: true));

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($driver->options['blogname'])->toBe('Botiga de prova')
        ->and($driver->coreVersion)->toBe('6.8.3')
        ->and(app(RestorePointGate::class)->isVerified($run))->toBeTrue()
        ->and(array_values(array_intersect($driver->writeCalls, ['dumpDatabase', 'archiveFiles', 'restoreFiles', 'restoreDatabase'])))
        ->toBe(['dumpDatabase', 'archiveFiles', 'dumpDatabase', 'archiveFiles', 'restoreFiles', 'restoreDatabase'])
        ->and($driver->wpCalls)->toContain(['cache', 'flush'])
        ->and(restoreStepOf($run, RestoreStep::NAME)->summary)->toContain("Punt de l'estat d'abans: «site-{$site->id}/run{$run->id}»");

    // The pre-restore point holds the changed state.
    $pre = Backup::query()->where('run_id', $run->id)->where('kind', BackupKind::Database)->firstOrFail();
    expect($driver->files["restore-points/{$pre->path}"] ?? '')->toContain('Canviat');

    $audit = AuditLogEntry::query()->where('action', 'backup.restore')->sole();
    expect($audit->result)->toBe(AuditResult::Success)
        ->and($audit->context)->toMatchArray([
            'run_id' => $run->id, 'restore_point' => $point->directory, 'kinds' => ['files', 'database'], 'restored' => ['files', 'database'],
            'pre_restore_point' => "site-{$site->id}/run{$run->id}", 'accepted_data_loss' => true,
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
        ->and(restoreStepOf($run, RestoreCheckStep::NAME)->error)->toContain('botiga (WooCommerce)')->toContain('--accept-data-loss')
        ->and($driver->writeCalls)->not->toContain('dumpDatabase', 'restoreDatabase')
        ->and(Backup::query()->where('run_id', $run->id)->count())->toBe(0);
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
        ->and(restoreStepOf($run, RestoreStep::NAME)->error)->toContain("No s'ha restaurat res")->toContain('--point=run'.$run->id)
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
        ->and(restoreStepOf($run, BackupStep::NAME)->status)->toBe(RunStepStatus::Failed)
        ->and(restoreStepOf($run, RestoreStep::NAME)->status)->toBe(RunStepStatus::Pending)
        ->and($driver->writeCalls)->not->toContain('restoreFiles');
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

describe('selection', function (): void {
    it('takes the verified site-local copies of a point, by its directory, its last segment or its run id', function (): void {
        $site = restoreRunSite();
        $driver = new FakeDriver;
        restoreRunPipeline($driver);
        $point = restoreRunPoint($site, $driver);
        $selection = app(RestoreSelection::class);

        foreach ([$point->directory, basename($point->directory)] as $ref) {
            expect($selection->select($site, $ref, [], [BackupKind::Database, BackupKind::Files], false)->backupIds)->toBe([
                'database' => $point->local(BackupKind::Database)?->id,
                'files' => $point->local(BackupKind::Files)?->id,
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

        expect(fn () => app(RestoreSelection::class)->rows($site, $request))->toThrow(RestoreRefused::class, "s'ha esborrat")
            ->and(fn () => RestoreRequest::fromRun(Run::factory()->create(['flags' => [RestoreRequest::POINT_FLAG => 'x', RestoreRequest::BACKUPS_FLAG => ['files' => '1']]])))
            ->toThrow(RestoreRefused::class);
    });
});
