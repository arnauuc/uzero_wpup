<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupFailed;
use App\Domain\Backups\BackupStep;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetException;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\FlysystemTarget;
use App\Domain\Backups\RestorePoint;
use App\Domain\Backups\RestorePointGate;
use App\Domain\Backups\RestorePointNotVerified;
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Backups\StoredObject;
use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Data\WorkFile;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Runs\Exceptions\StepTimedOut;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\RunEngine;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\AuditResult;
use App\Enums\BackupKind;
use App\Enums\BackupTargetType;
use App\Enums\RunStatus;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Models\AuditLogEntry;
use App\Models\Backup;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Run;
use App\Models\Site;
use App\Models\SiteProfile;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Tests\Support\FakeDriver;

/*
 * Restore points with verified integrity (UZEROWPUP-43) over FakeDriver, with a
 * Flysystem directory of the tool server standing for an external destination.
 */

/** A FakeDriver whose store and work files can be altered or broken. */
final class RestorePointDriver extends FakeDriver
{
    /** The store ("restore-points/…") reports another checksum than its bytes. */
    public bool $tamperStore = false;

    /** Work files outside the store report another checksum than their bytes. */
    public bool $tamperSource = false;

    public ?Throwable $storeChecksumFailure = null;

    public ?Throwable $moveFailure = null;

    public ?Throwable $archiveFailure = null;

    /** Added to the size moveWorkFile() reports. */
    public int $moveSizeDelta = 0;

    /** Checksums computed on objects of the store. */
    public int $storeChecksums = 0;

    public function statWorkFile(string $path, bool $withChecksum = false): ?WorkFile
    {
        $file = parent::statWorkFile($path, $withChecksum);
        if ($file === null || ! $withChecksum) {
            return $file;
        }
        $inStore = str_starts_with($path, 'restore-points/');
        if ($inStore) {
            $this->storeChecksums++;
            if ($this->storeChecksumFailure !== null) {
                throw $this->storeChecksumFailure;
            }
        }

        return ($inStore && $this->tamperStore) || (! $inStore && $this->tamperSource)
            ? new WorkFile($file->path, $file->sizeBytes, $file->modifiedAt, str_repeat('0', 64))
            : $file;
    }

    public function moveWorkFile(string $from, string $to): WorkFile
    {
        if ($this->moveFailure !== null) {
            throw $this->moveFailure;
        }
        $file = parent::moveWorkFile($from, $to);

        return new WorkFile($file->path, $file->sizeBytes + $this->moveSizeDelta, $file->modifiedAt);
    }

    public function archiveFiles(string $remotePath, array $excludes): RemoteFile
    {
        if ($this->archiveFailure !== null) {
            throw $this->archiveFailure;
        }

        return parent::archiveFiles($remotePath, $excludes);
    }
}

/** An external destination that stores other bytes than it is given, or breaks. */
final class LyingTarget implements BackupTarget
{
    /** @var array<string, string> */
    public array $objects = [];

    public ?Throwable $putFailure = null;

    public function put(string $key, $stream): StoredObject
    {
        if ($this->putFailure !== null) {
            throw $this->putFailure;
        }
        $this->objects[$key] = 'corrupt';

        return new StoredObject($key, 7);
    }

    public function readStream(string $key)
    {
        throw BackupTargetException::notFound($key);
    }

    public function exists(string $key): bool
    {
        return isset($this->objects[$key]);
    }

    public function checksum(string $key): string
    {
        return hash('sha256', $this->objects[$key] ?? throw BackupTargetException::notFound($key));
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }

    public function list(string $prefix = ''): array
    {
        return [];
    }
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    mkdir(externalDir(sys_get_temp_dir().'/wpup-restore-point-'.bin2hex(random_bytes(6))), 0700, true);
});

afterEach(function (): void {
    Carbon::setTestNow();
    File::deleteDirectory(externalDir());
});

/** The directory of the external destination of the current test. */
function externalDir(?string $set = null): string
{
    /** @var string $dir */
    static $dir = '';
    if ($set !== null) {
        $dir = $set;
    }

    return $dir;
}

/**
 * The service, with the site-local destination of the registry served by $driver.
 */
function restorePoints(FakeDriver $driver): RestorePointService
{
    app(BackupTargetRegistry::class)->register(BackupTargetType::SiteLocal, static fn (): BackupTarget => new SiteLocalTarget(
        $driver, app(SecretRedactor::class), app(HttpFactory::class), app(SiteLocalSettings::class), 'https://fake.test',
    ));

    return app(RestorePointService::class);
}

function restorePointSite(bool $includeUploads = false): Site
{
    $site = Site::factory()->create();
    SiteProfile::factory()->create(['site_id' => $site->id, 'include_uploads' => $includeUploads]);
    app(SiteLocalDestination::class)->attach($site);

    return $site;
}

/**
 * Links an external destination (type sftp) to $site, served by $target or the
 * Flysystem directory of the test.
 */
function externalDestination(Site $site, ?BackupTarget $target = null): BackupTargetRecord
{
    $record = BackupTargetRecord::factory()->create(['type' => BackupTargetType::Sftp, 'name' => 'extern-'.bin2hex(random_bytes(3))]);
    $site->backupTargets()->attach($record->id);
    $target ??= new FlysystemTarget(new Filesystem(new LocalFilesystemAdapter(externalDir())), app(SecretRedactor::class), 1_048_576);
    app(BackupTargetRegistry::class)->register(BackupTargetType::Sftp, static fn (): BackupTarget => $target);

    return $record;
}

/**
 * @param  array<string, mixed>  $flags
 */
function backupRun(Site $site, array $flags = []): Run
{
    return Run::factory()->create(['type' => RunType::Backup, 'site_id' => $site->id, 'flags' => $flags]);
}

function testActor(): AuditActor
{
    return AuditActor::system('tests');
}

it('records a verified row per file at the site-local destination, with size, checksum and expiry', function (): void {
    $site = restorePointSite();
    $run = backupRun($site);
    $driver = new RestorePointDriver;

    $point = restorePoints($driver)->create($site, $driver, $run, testActor());
    $local = app(SiteLocalDestination::class)->record();

    expect($point->directory)->toBe("site-{$site->id}/run{$run->id}")
        ->and($point->warnings)->toBe([])
        ->and($point->local(BackupKind::Database)?->path)->toBe("site-{$site->id}/run{$run->id}/db.sql.gz")
        ->and($point->local(BackupKind::Files)?->path)->toBe("site-{$site->id}/run{$run->id}/files.tar.gz")
        ->and(Backup::query()->where('run_id', $run->id)->count())->toBe(2);
    foreach (BackupKind::cases() as $kind) {
        $row = Backup::query()->where('run_id', $run->id)->where('kind', $kind)->sole();
        $stored = $driver->files["restore-points/{$row->path}"];
        expect($row->backup_target_id)->toBe($local->id)
            ->and($row->sha256)->toBe(hash('sha256', $stored))
            ->and($row->size_bytes)->toBe(strlen($stored))
            ->and($row->verified_at)->not->toBeNull()
            ->and($row->deleted_at)->toBeNull()
            ->and(abs($row->expires_at?->diffInSeconds(now()->addDays(7)) ?? 999))->toBeLessThan(60);
    }
    // The dump and the archive left the work directory, and the store was not hashed again after adopting them.
    expect(array_keys($driver->files))->each->toStartWith('restore-points/')
        ->and($driver->storeChecksums)->toBe(0)
        ->and(app(RestorePointGate::class)->isVerified($run))->toBeTrue();

    $audit = AuditLogEntry::query()->where('action', 'backup.created')->sole();
    expect($audit->result)->toBe(AuditResult::Success)
        ->and($audit->context)->toMatchArray(['run_id' => $run->id, 'kinds' => ['database', 'files'], 'reused' => false]);
});

it('takes uploads into the archive when the profile includes them', function (): void {
    $site = restorePointSite(includeUploads: true);
    $driver = new FakeDriver;

    $point = restorePoints($driver)->create($site, $driver, null, testActor());
    /** @var array{excludes: list<string>} $archive */
    $archive = unserialize($driver->files['restore-points/'.$point->local(BackupKind::Files)?->path]);

    expect($point->directory)->toMatch("#^site-{$site->id}/manual-\\d{14}-[0-9a-f]{6}$#")
        ->and($archive['excludes'])->not->toContain('wp-content/uploads');
});

it('refuses before dumping anything when the web could serve the store', function (): void {
    $site = restorePointSite();
    $driver = new FakeDriver;
    $driver->workDirInRoot = '.wpup';

    expect(fn () => restorePoints($driver)->create($site, $driver, backupRun($site), testActor()))
        ->toThrow(BackupFailed::class, 'accessibles pel web')
        ->and($driver->writeCalls)->not->toContain('dumpDatabase')
        ->and($driver->files)->toBe([])
        ->and(AuditLogEntry::query()->where('action', 'backup.created')->sole()->result)->toBe(AuditResult::Failure);
});

it('fails without a restore point when the file changed on the site before entering the store', function (): void {
    $site = restorePointSite();
    $run = backupRun($site);
    $driver = new RestorePointDriver;
    $driver->tamperSource = true;

    expect(fn () => restorePoints($driver)->create($site, $driver, $run, testActor()))
        ->toThrow(BackupFailed::class, 'no coincideix amb el checksum esperat')
        ->and(Backup::query()->count())->toBe(0)
        ->and($driver->files)->toBe([])
        ->and(app(RestorePointGate::class)->isVerified($run))->toBeFalse();
});

it('does not verify a copy the destination holds with another size, and fails without a restore point', function (): void {
    $site = restorePointSite();
    $run = backupRun($site);
    $driver = new RestorePointDriver;
    $driver->moveSizeDelta = 1;

    expect(fn () => restorePoints($driver)->create($site, $driver, $run, testActor()))
        ->toThrow(BackupFailed::class, 'no supera la verificació');

    $row = Backup::query()->where('run_id', $run->id)->sole();
    expect($row->kind)->toBe(BackupKind::Database)
        ->and($row->verified_at)->toBeNull()
        ->and($row->deleted_at)->not->toBeNull()
        // Nothing is left: neither the object nor the archive still in the work directory.
        ->and($driver->files)->toBe([])
        ->and(app(RestorePointGate::class)->isVerified($run))->toBeFalse()
        ->and(AuditLogEntry::query()->where('action', 'backup.created')->sole()->result)->toBe(AuditResult::Failure);
});

it('fails without any row when the dump misses tables', function (): void {
    $site = restorePointSite();
    $run = backupRun($site);
    $driver = new FakeDriver;
    $driver->dumpOmits = ['wp_posts'];

    expect(fn () => restorePoints($driver)->create($site, $driver, $run, testActor()))
        ->toThrow(BackupFailed::class, 'falten 1')
        ->and(Backup::query()->count())->toBe(0)
        ->and($driver->files)->toBe([])
        ->and(AuditLogEntry::query()->where('action', 'backup.created')->sole()->context)->toMatchArray(['run_id' => $run->id]);
});

it('cleans up and audits on any failure, not only the domain ones', function (string $where, Throwable $failure): void {
    $site = restorePointSite();
    $driver = new RestorePointDriver;
    $driver->{$where} = $failure;

    expect(fn () => restorePoints($driver)->create($site, $driver, backupRun($site), testActor()))->toThrow($failure::class);

    $audit = AuditLogEntry::query()->where('action', 'backup.created')->sole();
    expect($driver->files)->toBe([])
        ->and(Backup::query()->whereNotNull('verified_at')->whereNull('deleted_at')->count())->toBe(0)
        ->and($audit->result)->toBe(AuditResult::Failure)
        ->and($audit->context['exception'] ?? null)->toBe($failure::class);
})->with([
    'archive crashes' => ['archiveFailure', new RuntimeException('boom')],
    'step timed out while storing' => ['moveFailure', new StepTimedOut('timeout')],
]);

it('copies each file to the external destinations and verifies it there', function (): void {
    $site = restorePointSite();
    $run = backupRun($site);
    $record = externalDestination($site);
    $driver = new FakeDriver;

    $point = restorePoints($driver)->create($site, $driver, $run, testActor());
    $external = Backup::query()->where('backup_target_id', $record->id)->get();

    expect($point->backups)->toHaveCount(4)
        ->and($external)->toHaveCount(2);
    foreach ($external as $row) {
        $source = $point->local($row->kind);
        expect($row->verified_at)->not->toBeNull()
            ->and($row->run_id)->toBe($run->id)
            ->and($row->sha256)->toBe($source?->sha256)
            ->and($row->expires_at?->toIso8601String())->toBe($source?->expires_at?->toIso8601String())
            ->and(hash_file('sha256', externalDir().'/'.$row->path))->toBe($row->sha256);
    }
});

it('keeps the restore point valid locally when an external destination fails, with a warning', function (?Throwable $failure, string $reason): void {
    $site = restorePointSite();
    $run = backupRun($site);
    $lying = new LyingTarget;
    $lying->putFailure = $failure;
    $record = externalDestination($site, $lying);
    $driver = new FakeDriver;

    $point = restorePoints($driver)->create($site, $driver, $run, testActor());

    expect($point->warnings)->toHaveCount(2)
        ->and($point->warnings[0])->toContain($record->name)->toContain($reason)
        ->and(Backup::query()->where('backup_target_id', $record->id)->whereNull('verified_at')->count())->toBe(2)
        ->and($lying->objects)->toBe([])
        ->and(app(RestorePointGate::class)->isVerified($run))->toBeTrue();
})->with([
    'other bytes stored' => [null, 'SHA-256'],
    'unexpected error' => [new RuntimeException('connexió perduda'), 'error inesperat'],
]);

it('reuses a complete restore point when the step is retried, with the warnings of its archive', function (): void {
    $site = restorePointSite();
    $run = backupRun($site);
    $driver = new RestorePointDriver;
    $driver->configPath = '/var/www/wp-config.php';
    $service = restorePoints($driver);

    $first = $service->create($site, $driver, $run, testActor());
    $again = $service->create($site, $driver, $run, testActor());

    expect($first->warnings)->toHaveCount(1)
        ->and($again->reused)->toBeTrue()
        ->and($again->warnings)->toBe($first->warnings)
        ->and(array_map(fn (Backup $b): int => $b->id, $again->backups))->toBe(array_map(fn (Backup $b): int => $b->id, $first->backups))
        ->and(Backup::query()->count())->toBe(2);
});

it('gives an external copy with the same bytes the watermark it lacks, keeping its verification (UZEROWPUP-58)', function (): void {
    $site = restorePointSite();
    $run = backupRun($site);
    $driver = new FakeDriver;
    $service = restorePoints($driver);
    $record = externalDestination($site);
    $service->create($site, $driver, $run, testActor());
    $external = Backup::query()->where('backup_target_id', $record->id)->where('kind', BackupKind::Database)->sole();
    $local = Backup::query()->where('backup_target_id', '!=', $record->id)->where('kind', BackupKind::Database)->sole();
    $external->update(['order_watermark' => null]);

    $again = $service->create($site, $driver, $run, testActor());

    expect($again->reused)->toBeTrue()
        ->and($local->order_watermark)->not->toBeNull()
        ->and($external->fresh()?->order_watermark)->toBe($local->order_watermark)
        ->and($external->fresh()?->verified_at)->not->toBeNull();
});

it('redoes a restore point that is incomplete or whose stored bytes changed', function (Closure $break): void {
    $site = restorePointSite();
    $run = backupRun($site);
    $driver = new RestorePointDriver;
    $service = restorePoints($driver);
    $first = $service->create($site, $driver, $run, testActor());
    $break($first, $driver);

    $redone = $service->create($site, $driver, $run, testActor());

    expect($redone->reused)->toBeFalse()
        ->and(Backup::query()->whereNull('deleted_at')->whereNotNull('verified_at')->count())->toBe(2)
        ->and(Backup::query()->whereNull('deleted_at')->pluck('id')->intersect(array_map(fn (Backup $b): int => $b->id, $first->backups))->all())->toBe([])
        ->and(app(RestorePointGate::class)->isVerified($run))->toBeTrue();
})->with([
    'archive row missing' => [function (RestorePoint $point): void {
        $point->local(BackupKind::Files)?->delete();
    }],
    'stored bytes changed' => [function (RestorePoint $point, RestorePointDriver $driver): void {
        $driver->tamperStore = true;
    }],
]);

it('fails the step without discarding a good restore point when the store cannot be checked', function (): void {
    $site = restorePointSite();
    $run = backupRun($site);
    $driver = new RestorePointDriver;
    $service = restorePoints($driver);
    $service->create($site, $driver, $run, testActor());
    $files = $driver->files;
    $driver->storeChecksumFailure = new RemoteCommandFailed('timeout');

    expect(fn () => $service->create($site, $driver, $run, testActor()))->toThrow(BackupFailed::class, 'timeout')
        ->and($driver->files)->toBe($files)
        ->and(Backup::query()->whereNull('deleted_at')->whereNotNull('verified_at')->count())->toBe(2);
});

it('copies again an external file whose row or bytes do not match the current restore point', function (Closure $stale): void {
    $site = restorePointSite();
    $run = backupRun($site);
    $record = externalDestination($site);
    $driver = new FakeDriver;
    $service = restorePoints($driver);
    $service->create($site, $driver, $run, testActor());
    $row = Backup::query()->where('backup_target_id', $record->id)->where('kind', BackupKind::Database)->sole();
    $stale($row, externalDir().'/'.$row->path);

    $point = $service->create($site, $driver, $run, testActor());
    $row->refresh();
    $source = $point->local(BackupKind::Database);

    expect($point->reused)->toBeTrue()
        ->and($point->warnings)->toBe([])
        ->and($row->sha256)->toBe($source?->sha256)
        ->and($row->size_bytes)->toBe($source?->size_bytes)
        ->and($row->verified_at)->not->toBeNull()
        ->and(hash_file('sha256', externalDir().'/'.$row->path))->toBe($source?->sha256);
})->with([
    'row of an earlier attempt' => [function (Backup $row, string $file): void {
        $row->update(['sha256' => hash('sha256', 'old'), 'size_bytes' => 3, 'verified_at' => now()]);
        file_put_contents($file, 'old');
    }],
    'verified row, altered bytes' => [function (Backup $row, string $file): void {
        file_put_contents($file, 'altered');
    }],
]);

it('gives external rows added later the expiry of the restore point', function (): void {
    $site = restorePointSite();
    $run = backupRun($site);
    $driver = new FakeDriver;
    $service = restorePoints($driver);
    $first = $service->create($site, $driver, $run, testActor());
    Carbon::setTestNow(now()->addHours(2));
    $record = externalDestination($site);

    $service->create($site, $driver, $run, testActor());

    foreach (Backup::query()->where('backup_target_id', $record->id)->get() as $row) {
        expect($row->expires_at?->toIso8601String())->toBe($first->local($row->kind)?->expires_at?->toIso8601String());
    }
});

describe('gate', function (): void {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function localBackup(Run $run, BackupKind $kind, array $overrides = []): Backup
    {
        return Backup::factory()->create($overrides + [
            'site_id' => $run->site_id,
            'run_id' => $run->id,
            'backup_target_id' => app(SiteLocalDestination::class)->record()->id,
            'kind' => $kind,
            'path' => "site-{$run->site_id}/run{$run->id}/".RestorePointService::objectName($kind),
            'verified_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }

    it('lets an update through only with both kinds verified at the site-local destination for that run', function (): void {
        $run = Run::factory()->create();
        localBackup($run, BackupKind::Database);
        localBackup($run, BackupKind::Files);

        expect(app(RestorePointGate::class)->isVerified($run))->toBeTrue();
        app(RestorePointGate::class)->assertVerified($run);
    });

    it('blocks the update otherwise', function (Closure $setup): void {
        $run = Run::factory()->create();
        $setup($run);

        expect(app(RestorePointGate::class)->isVerified($run))->toBeFalse()
            ->and(fn () => app(RestorePointGate::class)->assertVerified($run))->toThrow(RestorePointNotVerified::class, 'no té cap punt de restauració verificat');
    })->with([
        'no backup' => [fn (Run $run) => null],
        'database only' => [fn (Run $run) => localBackup($run, BackupKind::Database)],
        'files unverified' => [function (Run $run): void {
            localBackup($run, BackupKind::Database);
            localBackup($run, BackupKind::Files, ['verified_at' => null]);
        }],
        'files purged' => [function (Run $run): void {
            localBackup($run, BackupKind::Database);
            localBackup($run, BackupKind::Files, ['deleted_at' => now()]);
        }],
        'expired' => [function (Run $run): void {
            localBackup($run, BackupKind::Database, ['expires_at' => now()->subMinute()]);
            localBackup($run, BackupKind::Files, ['expires_at' => now()->subMinute()]);
        }],
        'another run' => [function (Run $run): void {
            $other = Run::factory()->create(['site_id' => $run->site_id]);
            localBackup($other, BackupKind::Database);
            localBackup($other, BackupKind::Files);
        }],
        'two different restore points' => [function (Run $run): void {
            localBackup($run, BackupKind::Database, ['path' => "site-{$run->site_id}/a/db.sql.gz"]);
            localBackup($run, BackupKind::Files, ['path' => "site-{$run->site_id}/b/files.tar.gz"]);
        }],
        'external destination only' => [function (Run $run): void {
            $external = BackupTargetRecord::factory()->create(['type' => BackupTargetType::Sftp]);
            localBackup($run, BackupKind::Database, ['backup_target_id' => $external->id]);
            localBackup($run, BackupKind::Files, ['backup_target_id' => $external->id]);
        }],
    ]);
});

describe('run', function (): void {
    beforeEach(function (): void {
        Redis::connection('locks')->command('flushdb');
        config(['wpup.runs.heartbeat_seconds' => 1]);
        Http::fake(['*' => Http::response('', 500)]);
    });

    function backupPipeline(FakeDriver $driver): void
    {
        $driverFor = static fn (Site $s, AuditActor $a): SiteDriver => $driver;
        $service = restorePoints($driver);
        app(PipelineResolver::class)->register(RunType::Backup, static fn (): Pipeline => new Pipeline([
            new InventoryStep(app(SiteInventory::class), $driverFor, app(RunAuthorization::class), 60),
            new BackupStep($service, $driverFor, app(RunAuthorization::class), 60),
        ]));
    }

    it('registers the backup pipeline: a fresh inventory, then the restore point', function (): void {
        $pipeline = app(PipelineResolver::class)->resolve(Run::factory()->create(['type' => RunType::Backup]));

        expect(array_map(fn ($s): string => $s->name(), $pipeline->steps))->toBe([InventoryStep::NAME, BackupStep::NAME])
            ->and($pipeline->get(BackupStep::NAME))->toBeInstanceOf(BackupStep::class);
    });

    it('makes the restore point in a run and stores its summary in the step', function (): void {
        $site = restorePointSite();
        $driver = new FakeDriver;
        backupPipeline($driver);

        $run = app(RunEngine::class)->execute(backupRun($site));
        $step = $run->steps()->where('name', BackupStep::NAME)->firstOrFail();
        /** @var array{directory: string, backups: list<array{verified: bool}>} $result */
        $result = $step->result;

        expect($run->status)->toBe(RunStatus::Succeeded)
            ->and($step->summary)->toStartWith('Punt de restauració verificat: BD ')->toContain(', codi ')
            ->and($result['directory'])->toBe("site-{$site->id}/run{$run->id}")
            ->and(array_column($result['backups'], 'verified'))->toBe([true, true])
            ->and(app(RestorePointGate::class)->isVerified($run))->toBeTrue();
    });

    it('fails the run when the restore point cannot be verified', function (): void {
        $site = restorePointSite();
        $driver = new RestorePointDriver;
        $driver->moveSizeDelta = 1;
        backupPipeline($driver);

        $run = app(RunEngine::class)->execute(backupRun($site));

        expect($run->status)->not->toBe(RunStatus::Succeeded)
            ->and($run->steps()->where('name', BackupStep::NAME)->firstOrFail()->status)->toBe(RunStepStatus::Failed)
            ->and(app(RestorePointGate::class)->isVerified($run))->toBeFalse();
    });

    it('only describes the restore point in a dry run, and changes nothing', function (): void {
        $site = restorePointSite();
        $driver = new FakeDriver;
        backupPipeline($driver);

        $run = app(RunEngine::class)->execute(backupRun($site, ['dry_run' => true]));
        $step = $run->steps()->where('name', BackupStep::NAME)->firstOrFail();

        expect($step->status)->toBe(RunStepStatus::Skipped)
            ->and($step->summary)->toStartWith('[dry-run] Farà un punt de restauració')
            ->and(Backup::query()->count())->toBe(0)
            ->and($driver->files)->toBe([])
            ->and($driver->writeCalls)->not->toContain('dumpDatabase')
            ->and(AuditLogEntry::query()->where('action', 'backup.created')->count())->toBe(0);
    });
});
