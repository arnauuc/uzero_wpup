<?php

declare(strict_types=1);

use App\Domain\Artifacts\ArtifactRetention;
use App\Domain\Artifacts\ArtifactStore;
use App\Domain\Artifacts\PackageSource;
use App\Domain\Audit\AuditActor;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\ComponentType;
use App\Models\AuditLogEntry;
use App\Models\Backup;
use App\Models\Run;
use App\Models\RunArtifact;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config(['wpup.artifacts.dir' => sys_get_temp_dir().'/wpup-artifacts-'.bin2hex(random_bytes(6))]);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg(artifactsRoot()));
});

function storedArtifact(Run $run, CarbonImmutable $createdAt, string $slug = 'plugin-x'): RunArtifact
{
    $store = app(ArtifactStore::class);
    $temporary = $store->temporaryFile($run->id);
    file_put_contents($temporary, 'zip bytes');
    $source = PackageSource::create(ComponentType::Plugin, $slug, '1.0.0', 'https://downloads.wordpress.org/plugin/x.zip', app(SecretRedactor::class));
    $artifact = $store->commit($run->id, $source, $temporary, hash('sha256', 'zip bytes'), 9);
    $row = RunArtifact::query()->findOrFail($artifact->id);
    $row->forceFill(['created_at' => $createdAt])->save();

    return $row;
}

it('purges only artifacts older than the minimum whose restore point is gone, and audits it', function (): void {
    $root = artifactsRoot();
    $now = CarbonImmutable::parse('2026-06-01 03:00:00');
    $old = $now->subDays(31);

    $gone = Run::factory()->create();
    Backup::factory()->create(['run_id' => $gone->id, 'site_id' => $gone->site_id, 'deleted_at' => $now->subDays(20)]);
    $purgeable = storedArtifact($gone, $old);

    $noBackup = Run::factory()->create();
    $alsoPurgeable = storedArtifact($noBackup, $old);

    $held = Run::factory()->create();
    Backup::factory()->create(['run_id' => $held->id, 'site_id' => $held->site_id, 'protected' => true]);
    $kept = storedArtifact($held, $old);

    $recent = storedArtifact(Run::factory()->create(), $now->subDays(29));

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
    expect($audit->context)->toMatchArray(['purged' => 2, 'bytes' => 18, 'runs' => [$gone->id, $noBackup->id], 'failures' => []]);
});
