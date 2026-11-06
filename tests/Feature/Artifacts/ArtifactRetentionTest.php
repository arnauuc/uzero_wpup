<?php

declare(strict_types=1);

use App\Domain\Artifacts\ArtifactRetention;
use App\Domain\Artifacts\ArtifactStore;
use App\Domain\Artifacts\PackageSource;
use App\Domain\Artifacts\PackageUrlGuard;
use App\Domain\Audit\AuditActor;
use App\Domain\Locks\SiteLockManager;
use App\Enums\ComponentType;
use App\Enums\RunStatus;
use App\Models\AuditLogEntry;
use App\Models\Backup;
use App\Models\Run;
use App\Models\RunArtifact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    config(['wpup.artifacts.dir' => sys_get_temp_dir().'/wpup-artifacts-'.bin2hex(random_bytes(6))]);
    Redis::connection('locks')->command('flushdb');
});

afterEach(function (): void {
    exec('chmod -R u+w '.escapeshellarg(artifactsRoot()).' 2>/dev/null; rm -rf '.escapeshellarg(artifactsRoot()));
});

/**
 * @param  array<string, mixed>  $run
 */
function finishedRun(array $run = []): Run
{
    return Run::factory()->create(['status' => RunStatus::Succeeded, ...$run]);
}

function storedArtifact(Run $run, CarbonImmutable $createdAt, string $slug = 'plugin-x'): RunArtifact
{
    $store = app(ArtifactStore::class);
    $temporary = $store->temporaryFile($run->id);
    file_put_contents($temporary, 'zip bytes');
    $source = PackageSource::create(ComponentType::Plugin, $slug, '1.0.0', 'https://downloads.wordpress.org/plugin/x.zip', app(PackageUrlGuard::class));
    $artifact = $store->commit($run->id, $source, $temporary, hash('sha256', 'zip bytes'), 9);
    $row = RunArtifact::query()->findOrFail($artifact->id);
    $row->forceFill(['created_at' => $createdAt])->save();

    return $row;
}

it('purges only artifacts older than the minimum whose restore point is gone, and audits it', function (): void {
    $root = artifactsRoot();
    $now = CarbonImmutable::parse('2026-06-01 03:00:00');
    $old = $now->subDays(31);

    $gone = finishedRun();
    Backup::factory()->create(['run_id' => $gone->id, 'site_id' => $gone->site_id, 'deleted_at' => $now->subDays(20)]);
    $purgeable = storedArtifact($gone, $old);

    $noBackup = finishedRun();
    $alsoPurgeable = storedArtifact($noBackup, $old);

    $held = finishedRun();
    Backup::factory()->create(['run_id' => $held->id, 'site_id' => $held->site_id, 'protected' => true]);
    $kept = storedArtifact($held, $old);

    $recent = storedArtifact(finishedRun(), $now->subDays(29));

    $retention = app(ArtifactRetention::class);
    expect($retention->purgeable($now)->pluck('id')->all())->toBe([$purgeable->id, $alsoPurgeable->id]);

    $dry = $retention->purge(AuditActor::system(), $now, dryRun: true);
    expect($dry->count)->toBe(2)->and($dry->dryRun)->toBeTrue()
        ->and(file_exists("{$root}/{$purgeable->path}"))->toBeTrue()
        ->and(AuditLogEntry::query()->where('action', 'artifacts.purged')->count())->toBe(0);

    $result = $retention->purge(AuditActor::system(), $now);

    expect($result->count)->toBe(2)->and($result->bytes)->toBe(18)->and($result->failures)->toBe([])
        ->and(file_exists("{$root}/{$purgeable->path}"))->toBeFalse()
        ->and(is_dir("{$root}/run-{$gone->id}"))->toBeFalse()
        ->and($purgeable->refresh()->purged_at?->equalTo($now))->toBeTrue()
        ->and(file_exists("{$root}/{$kept->path}"))->toBeTrue()
        ->and(file_exists("{$root}/{$recent->path}"))->toBeTrue()
        ->and($retention->purgeable($now)->all())->toBe([]);

    $audit = AuditLogEntry::query()->where('action', 'artifacts.purged')->sole();
    expect($audit->context)->toMatchArray(['purged' => 2, 'bytes' => 18, 'runs' => [$gone->id, $noBackup->id], 'failed' => 0, 'failures' => []]);
});

it('keeps the artifacts of unfinished, unreviewed, parent and locked runs, like restore points', function (): void {
    $now = CarbonImmutable::parse('2026-06-01 03:00:00');
    $old = $now->subDays(40);

    $interrupted = storedArtifact(finishedRun(['status' => RunStatus::Interrupted]), $old);
    $unreviewed = storedArtifact(finishedRun(['status' => RunStatus::RolledBack]), $old);
    $reviewed = storedArtifact(finishedRun(['status' => RunStatus::RolledBack, 'reviewed_at' => $now]), $old);
    $parent = finishedRun();
    $ofParent = storedArtifact($parent, $old);
    Run::factory()->create(['site_id' => $parent->site_id, 'parent_run_id' => $parent->id, 'status' => RunStatus::Running]);
    $lockedRun = finishedRun();
    $ofLocked = storedArtifact($lockedRun, $old);
    $lease = app(SiteLockManager::class)->acquire($lockedRun->site()->firstOrFail(), 'una altra execució');

    try {
        $ids = app(ArtifactRetention::class)->purgeable($now)->pluck('id')->all();
    } finally {
        $lease->release();
    }

    expect($ids)->toBe([$reviewed->id])
        ->and($ids)->not->toContain($interrupted->id, $unreviewed->id, $ofParent->id, $ofLocked->id);
});

it('goes on after an artifact that cannot be purged and audits the failure', function (): void {
    $now = CarbonImmutable::parse('2026-06-01 03:00:00');
    $stuck = storedArtifact(finishedRun(), $now->subDays(31));
    $fine = storedArtifact(finishedRun(), $now->subDays(31));
    chmod(dirname(artifactsRoot()."/{$stuck->path}"), 0500);

    $result = app(ArtifactRetention::class)->purge(AuditActor::system(), $now);

    expect($result->count)->toBe(1)->and($result->failures)->toHaveCount(1)
        ->and($stuck->refresh()->purged_at)->toBeNull()
        ->and($fine->refresh()->purged_at)->not->toBeNull();
    $audit = AuditLogEntry::query()->where('action', 'artifacts.purged')->sole();
    expect($audit->context)->toMatchArray(['purged' => 1, 'failed' => 1])
        ->and($audit->result->value)->toBe('failure');
});
