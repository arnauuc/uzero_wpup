<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupFailed;
use App\Domain\Backups\BackupStep;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetException;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\FlysystemTarget;
use App\Domain\Backups\RestorePointGate;
use App\Domain\Backups\RestorePointNotVerified;
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\StoredObject;
use App\Domain\Drivers\Data\WorkFile;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Inventory\SiteInventory;
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

/** A FakeDriver whose store returns another checksum than the bytes it holds. */
final class TamperedStoreDriver extends FakeDriver
{
    public function statWorkFile(string $path, bool $withChecksum = false): ?WorkFile
    {
        $file = parent::statWorkFile($path, $withChecksum);

        return $file !== null && $withChecksum && str_starts_with($path, 'restore-points/')
            ? new WorkFile($file->path, $file->sizeBytes, $file->modifiedAt, str_repeat('0', 64))
            : $file;
    }
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    mkdir(externalDir(sys_get_temp_dir().'/wpup-restore-point-'.bin2hex(random_bytes(6))), 0700, true);
});

afterEach(function (): void {
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

function restorePointSite(bool $includeUploads = false): Site
{
    $site = Site::factory()->create();
    SiteProfile::factory()->create(['site_id' => $site->id, 'include_uploads' => $includeUploads]);
    app(SiteLocalDestination::class)->attach($site);

    return $site;
}

/**
 * Links an external destination (type sftp) to $site, served by $target or a
 * Flysystem directory.
 */
function externalDestination(Site $site, string $dir, ?BackupTarget $target = null): BackupTargetRecord
{
    $record = BackupTargetRecord::factory()->create(['type' => BackupTargetType::Sftp, 'name' => 'extern-'.bin2hex(random_bytes(3))]);
    $site->backupTargets()->attach($record->id);
    $target ??= new FlysystemTarget(new Filesystem(new LocalFilesystemAdapter($dir)), app(SecretRedactor::class), 1_048_576);
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

it('records a verified row per file at the site-local destination, with size, checksum and expiry', function (): void {
    $site = restorePointSite();
    $run = backupRun($site);
    $driver = new FakeDriver;

    $point = app(RestorePointService::class)->create($site, $driver, $run, AuditActor::system('tests'));
    $rows = Backup::query()->where('run_id', $run->id)->orderBy('id')->get();
    $local = app(SiteLocalDestination::class)->record();

    expect($point->directory)->toBe("site-{$site->id}/run{$run->id}")
        ->and($point->warnings)->toBe([])
        ->and($rows->map(fn (Backup $b): string => "{$b->kind->value}:{$b->path}")->all())->toBe([
            "database:site-{$site->id}/run{$run->id}/db.sql.gz",
            "files:site-{$site->id}/run{$run->id}/files.tar.gz",
        ]);
    foreach ($rows as $row) {
        $stored = $driver->files["restore-points/{$row->path}"];
        expect($row->backup_target_id)->toBe($local->id)
            ->and($row->sha256)->toBe(hash('sha256', $stored))
            ->and($row->size_bytes)->toBe(strlen($stored))
            ->and($row->verified_at)->not->toBeNull()
            ->and($row->deleted_at)->toBeNull()
            ->and(abs($row->expires_at?->diffInSeconds(now()->addDays(7)) ?? 999))->toBeLessThan(60);
    }
    // The dump and the archive left the work directory: they live in the store only.
    expect(array_keys($driver->files))->each->toStartWith('restore-points/')
        ->and(app(RestorePointGate::class)->isVerified($run))->toBeTrue();

    $audit = AuditLogEntry::query()->where('action', 'backup.created')->sole();
    expect($audit->result)->toBe(AuditResult::Success)
        ->and($audit->context)->toMatchArray(['run_id' => $run->id, 'kinds' => ['database', 'files'], 'reused' => false]);
});

it('takes uploads into the archive when the profile includes them', function (): void {
    $site = restorePointSite(includeUploads: true);
    $driver = new FakeDriver;

    $point = app(RestorePointService::class)->create($site, $driver, null, AuditActor::system('tests'));
    /** @var array{excludes: list<string>} $archive */
    $archive = unserialize($driver->files['restore-points/'.$point->backups[1]->path]);

    expect($point->directory)->toMatch("#^site-{$site->id}/manual-\\d{14}-[0-9a-f]{6}$#")
        ->and($archive['excludes'])->not->toContain('wp-content/uploads');
});

it('does not verify a copy whose checksum at the destination differs, and fails without a restore point', function (): void {
    $site = restorePointSite();
    $run = backupRun($site);
    $driver = new TamperedStoreDriver;

    expect(fn () => app(RestorePointService::class)->create($site, $driver, $run, AuditActor::system('tests')))
        ->toThrow(BackupFailed::class, 'no supera la verificació');

    $row = Backup::query()->where('run_id', $run->id)->sole();
    expect($row->kind)->toBe(BackupKind::Database)
        ->and($row->verified_at)->toBeNull()
        ->and($row->deleted_at)->not->toBeNull()
        // Nothing is left: neither the corrupt object nor the archive still in the work directory.
        ->and($driver->files)->toBe([])
        ->and(app(RestorePointGate::class)->isVerified($run))->toBeFalse()
        ->and(AuditLogEntry::query()->where('action', 'backup.created')->sole()->result)->toBe(AuditResult::Failure);
});

it('fails without any row when the dump misses tables', function (): void {
    $site = restorePointSite();
    $run = backupRun($site);
    $driver = new FakeDriver;
    $driver->dumpOmits = ['wp_posts'];

    expect(fn () => app(RestorePointService::class)->create($site, $driver, $run, AuditActor::system('tests')))
        ->toThrow(BackupFailed::class, 'falten 1')
        ->and(Backup::query()->count())->toBe(0)
        ->and($driver->files)->toBe([])
        ->and(AuditLogEntry::query()->where('action', 'backup.created')->sole()->context)->toMatchArray(['run_id' => $run->id]);
});

it('copies each file to the external destinations and verifies it there', function (): void {
    $site = restorePointSite();
    $run = backupRun($site);
    $record = externalDestination($site, externalDir());

    $point = app(RestorePointService::class)->create($site, new FakeDriver, $run, AuditActor::system('tests'));
    $external = Backup::query()->where('backup_target_id', $record->id)->orderBy('id')->get();

    expect($point->backups)->toHaveCount(4)
        ->and($external)->toHaveCount(2)
        ->and($external->every(fn (Backup $b): bool => $b->verified_at !== null && $b->run_id === $run->id))->toBeTrue();
    foreach ($external as $row) {
        expect(hash_file('sha256', externalDir().'/'.$row->path))->toBe($row->sha256);
    }
});

it('keeps the restore point valid locally when an external destination fails, with a warning', function (): void {
    $site = restorePointSite();
    $run = backupRun($site);
    // A destination that stores other bytes than it was given.
    $lying = new class implements BackupTarget
    {
        /** @var array<string, string> */
        public array $objects = [];

        public function put(string $key, $stream): StoredObject
        {
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
    };
    $record = externalDestination($site, externalDir(), $lying);

    $point = app(RestorePointService::class)->create($site, new FakeDriver, $run, AuditActor::system('tests'));

    expect($point->warnings)->toHaveCount(2)
        ->and($point->warnings[0])->toContain($record->name)->toContain('SHA-256')
        ->and(Backup::query()->where('backup_target_id', $record->id)->whereNull('verified_at')->count())->toBe(2)
        ->and($lying->objects)->toBe([])
        ->and(app(RestorePointGate::class)->isVerified($run))->toBeTrue();
});

it('reuses a complete restore point when the step is retried, and redoes an incomplete one', function (): void {
    $site = restorePointSite();
    $run = backupRun($site);
    $driver = new FakeDriver;
    $service = app(RestorePointService::class);

    $first = $service->create($site, $driver, $run, AuditActor::system('tests'));
    $again = $service->create($site, $driver, $run, AuditActor::system('tests'));

    expect($again->reused)->toBeTrue()
        ->and(array_map(fn (Backup $b): int => $b->id, $again->backups))->toBe(array_map(fn (Backup $b): int => $b->id, $first->backups))
        ->and(Backup::query()->count())->toBe(2);

    // A crash after the dump was stored: the archive row is missing.
    $first->backups[1]->delete();
    $redone = $service->create($site, $driver, $run, AuditActor::system('tests'));

    expect($redone->reused)->toBeFalse()
        ->and(Backup::query()->whereNull('deleted_at')->whereNotNull('verified_at')->count())->toBe(2)
        ->and(Backup::query()->whereNotNull('deleted_at')->pluck('id')->all())->toBe([$first->backups[0]->id])
        ->and(app(RestorePointGate::class)->isVerified($run))->toBeTrue();
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
            'path' => "site-{$run->site_id}/run{$run->id}/".($kind === BackupKind::Database ? 'db.sql.gz' : 'files.tar.gz'),
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
        app(PipelineResolver::class)->register(RunType::Backup, static fn (): Pipeline => new Pipeline([
            new InventoryStep(app(SiteInventory::class), $driverFor, app(RunAuthorization::class), 60),
            new BackupStep(app(RestorePointService::class), $driverFor, app(RunAuthorization::class), 60),
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
            ->and($step->summary)->toStartWith('Punt de restauració verificat: BD ')
            ->and($result['directory'])->toBe("site-{$site->id}/run{$run->id}")
            ->and(array_column($result['backups'], 'verified'))->toBe([true, true])
            ->and(app(RestorePointGate::class)->isVerified($run))->toBeTrue();
    });

    it('fails the run when the restore point cannot be verified', function (): void {
        $site = restorePointSite();
        backupPipeline(new TamperedStoreDriver);

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
