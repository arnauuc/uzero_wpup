<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetException;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\PlannedPurge;
use App\Domain\Backups\PruneFailure;
use App\Domain\Backups\PruneResult;
use App\Domain\Backups\PurgeReason;
use App\Domain\Backups\RestorePointRetention;
use App\Domain\Backups\RetainedPoint;
use App\Domain\Backups\RetentionHold;
use App\Domain\Backups\StoredObject;
use App\Domain\Locks\LockLevel;
use App\Domain\Locks\SiteLockManager;
use App\Domain\Runs\Exceptions\RunException;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunEngine;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\AuditResult;
use App\Enums\BackupTargetType;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\AuditLogEntry;
use App\Models\Backup;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Run;
use App\Models\Site;
use Illuminate\Support\Facades\Redis;
use Tests\Support\RetentionFixture;
use Tests\Support\Runs\ScriptedCompensableStep;
use Tests\Support\UndeletableTarget;

/*
 * Retention of restore points (UZEROWPUP-44, ADR-0009): 7 days, at most 2 per
 * site, protected points, and never a point a run may still need. The
 * site-local store and an external destination are Flysystem directories.
 */

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    RetentionFixture::setUp();
});

afterEach(function (): void {
    RetentionFixture::tearDown();
});

/**
 * @return array<string, string> directory => reason
 */
function retentionPurges(?Site $site = null): array
{
    $plan = app(RestorePointRetention::class)->plan($site === null ? null : [$site->id]);
    $out = [];
    foreach ($plan->purges as $purge) {
        /** @var PlannedPurge $purge */
        $out[$purge->point->directory] = $purge->reason->value;
    }

    return $out;
}

/**
 * @return array<string, string> directory => hold
 */
function retentionHolds(?Site $site = null): array
{
    $plan = app(RestorePointRetention::class)->plan($site === null ? null : [$site->id]);
    $out = [];
    foreach ($plan->held as $held) {
        /** @var RetainedPoint $held */
        $out[$held->point->directory] = $held->hold->value;
    }

    return $out;
}

function retentionPrune(): PruneResult
{
    $retention = app(RestorePointRetention::class);

    return $retention->prune($retention->plan(), AuditActor::system('tests'), 'tests');
}

it('keeps the two newest points of a site and purges the oldest, at every destination', function (): void {
    $site = RetentionFixture::site(external: true);
    $old = RetentionFixture::point($site, RetentionFixture::run($site), 3);
    $middle = RetentionFixture::point($site, RetentionFixture::run($site), 2);
    $new = RetentionFixture::point($site, RetentionFixture::run($site), 1);

    expect(retentionPurges())->toBe([$old => PurgeReason::OverCap->value]);

    $result = retentionPrune();

    expect($result->failed())->toBeFalse()
        ->and($result->purged)->toHaveCount(1)
        ->and(RetentionFixture::live($site))->toBe([$middle, $new])
        ->and(RetentionFixture::stored($old))->toBeFalse()
        ->and(RetentionFixture::stored($old, external: true))->toBeFalse()
        ->and(RetentionFixture::stored($middle))->toBeTrue()
        ->and(RetentionFixture::stored($middle, external: true))->toBeTrue()
        ->and(Backup::query()->where('path', 'like', "{$old}/%")->whereNotNull('deleted_at')->count())->toBe(4);

    $audit = AuditLogEntry::query()->where('action', 'backups.pruned')->sole();
    expect($audit->result)->toBe(AuditResult::Success)
        ->and($audit->actor)->toBe('tests')
        ->and($audit->object_type)->toBe('site')
        ->and($audit->object_id)->toBe((string) $site->id)
        ->and($audit->context['trigger'] ?? null)->toBe('tests')
        ->and($audit->context['deleted_backup_ids'] ?? null)->toEqual(Backup::query()->where('path', 'like', "{$old}/%")->orderBy('id')->pluck('id')->all())
        ->and($audit->context['purged'] ?? null)->toEqual([[
            'directory' => $old, 'run_id' => Backup::query()->where('path', 'like', "{$old}/%")->value('run_id'),
            'reason' => 'over_cap', 'backup_ids' => Backup::query()->where('path', 'like', "{$old}/%")->orderBy('id')->pluck('id')->all(),
        ]]);
});

it('keeps a correct point for 7 days and purges it once expired', function (): void {
    $site = RetentionFixture::site();
    $fresh = RetentionFixture::point($site, RetentionFixture::run($site), 6);
    $expired = RetentionFixture::point($site, RetentionFixture::run($site), 8);

    expect(retentionPurges())->toBe([$expired => PurgeReason::Expired->value]);
    retentionPrune();
    expect(RetentionFixture::live($site))->toBe([$fresh]);
});

it('does nothing when every site is within the retention', function (): void {
    $site = RetentionFixture::site();
    RetentionFixture::point($site, RetentionFixture::run($site), 2);
    RetentionFixture::point($site, RetentionFixture::run($site), 1);

    $result = retentionPrune();

    expect($result->isEmpty())->toBeTrue()
        ->and(RetentionFixture::live($site))->toHaveCount(2)
        ->and(AuditLogEntry::query()->where('action', 'backups.pruned')->exists())->toBeFalse();
});

it('never purges a protected point, and purges the oldest unprotected one instead', function (): void {
    $site = RetentionFixture::site();
    $protected = RetentionFixture::point($site, RetentionFixture::run($site, RunStatus::RolledBack, ['reviewed_at' => now()]), 9, protected: true);
    $middle = RetentionFixture::point($site, RetentionFixture::run($site), 2);
    $new = RetentionFixture::point($site, RetentionFixture::run($site), 1);

    expect(retentionPurges())->toBe([$middle => 'over_cap'])
        ->and(retentionHolds())->toBe([$protected => RetentionHold::Protected->value]);

    retentionPrune();

    expect(RetentionFixture::live($site))->toBe([$protected, $new])
        ->and(RetentionFixture::stored($protected))->toBeTrue();
});

it('never purges the point of a run that has not finished', function (RunStatus $status): void {
    $site = RetentionFixture::site();
    $point = RetentionFixture::point($site, RetentionFixture::run($site, $status), 9);

    expect(retentionPurges())->toBe([])
        ->and(retentionHolds())->toBe([$point => RetentionHold::RunInProgress->value]);
    retentionPrune();
    expect(RetentionFixture::live($site))->toBe([$point]);
})->with([RunStatus::Queued, RunStatus::Running, RunStatus::RollingBack, RunStatus::Interrupted]);

it('holds the point of a reverted or failed run until it is reviewed, even if it was not marked protected', function (RunStatus $status): void {
    $site = RetentionFixture::site();
    $run = RetentionFixture::run($site, $status);
    $point = RetentionFixture::point($site, $run, 9);

    expect(retentionPurges())->toBe([])
        ->and(retentionHolds())->toBe(["site-{$site->id}/run{$run->id}" => RetentionHold::IncidentNotReviewed->value]);

    $run->update(['reviewed_at' => now()]);

    expect(retentionPurges())->toBe([$point => 'expired']);
})->with([RunStatus::RolledBack, RunStatus::Failed]);

it('holds the point of a succeeded update a rollback undid (runs.reverted_at) until it is reviewed', function (): void {
    $site = RetentionFixture::site();
    $run = RetentionFixture::run($site, RunStatus::Succeeded, ['reverted_at' => now()]);
    $point = RetentionFixture::point($site, $run, 9);

    expect(retentionPurges())->toBe([])
        ->and(retentionHolds())->toBe([$point => RetentionHold::IncidentNotReviewed->value]);

    $run->update(['reviewed_at' => now()]);

    expect(retentionPurges())->toBe([$point => 'expired']);
});

it('never purges the point of a run an unfinished run was launched from', function (): void {
    $site = RetentionFixture::site();
    $update = RetentionFixture::run($site);
    $point = RetentionFixture::point($site, $update, 9);
    RetentionFixture::point($site, RetentionFixture::run($site), 1);
    Run::factory()->create(['site_id' => $site->id, 'type' => RunType::Rollback, 'status' => RunStatus::Queued, 'parent_run_id' => $update->id]);

    expect(retentionHolds())->toBe([$point => RetentionHold::NeededByRun->value])
        ->and(retentionPurges())->toBe([]);
});

it('keeps the newest verified point of a site with an unfinished run, even expired', function (): void {
    $site = RetentionFixture::site();
    $older = RetentionFixture::point($site, RetentionFixture::run($site), 10);
    $newest = RetentionFixture::point($site, RetentionFixture::run($site), 9);
    Run::factory()->create(['site_id' => $site->id, 'type' => RunType::Rollback, 'status' => RunStatus::Running]);

    expect(retentionPurges())->toBe([$older => 'expired'])
        ->and(retentionHolds())->toBe([$newest => RetentionHold::NewestOfActiveSite->value]);
});

it('does not touch a site whose lock is held, in the plan or when purging', function (): void {
    $site = RetentionFixture::site();
    $old = RetentionFixture::point($site, RetentionFixture::run($site), 9);
    $lease = app(SiteLockManager::class)->acquire($site, 'una altra execució');

    try {
        expect(retentionPurges())->toBe([])
            ->and(retentionHolds())->toBe([$old => RetentionHold::SiteBusy->value]);
    } finally {
        $lease->release();
    }

    // Locked between the plan and the purge: the site is skipped.
    $retention = app(RestorePointRetention::class);
    $plan = $retention->plan();
    $lease = app(SiteLockManager::class)->acquire($site, 'una altra execució');

    try {
        $result = $retention->prune($plan, AuditActor::system('tests'), 'tests');
    } finally {
        $lease->release();
    }

    expect($result->purged)->toBe([])
        ->and(array_map(static fn (Site $s): int => $s->id, $result->busySites))->toBe([$site->id])
        ->and(RetentionFixture::live($site))->toBe([$old])
        ->and(RetentionFixture::stored($old))->toBeTrue();
});

it('recomputes the plan under the lock: a point a run started to need meanwhile is kept', function (): void {
    $site = RetentionFixture::site();
    $run = RetentionFixture::run($site);
    $point = RetentionFixture::point($site, $run, 9);
    $retention = app(RestorePointRetention::class);
    $plan = $retention->plan();
    Run::factory()->create(['site_id' => $site->id, 'type' => RunType::Rollback, 'status' => RunStatus::Queued, 'parent_run_id' => $run->id]);

    $result = $retention->prune($plan, AuditActor::system('tests'), 'tests');

    expect($result->purged)->toBe([])
        ->and(RetentionFixture::live($site))->toBe([$point])
        ->and(RetentionFixture::stored($point))->toBeTrue();
});

it('keeps the row of a file it could not delete, reports it redacted and still purges the rest', function (): void {
    $broken = RetentionFixture::site(external: true);
    $other = RetentionFixture::site();
    $brokenPoint = RetentionFixture::point($broken, RetentionFixture::run($broken), 9);
    $otherPoint = RetentionFixture::point($other, RetentionFixture::run($other), 9);
    app(BackupTargetRegistry::class)->register(BackupTargetType::Sftp, static fn (): BackupTarget => new UndeletableTarget);
    // A secret revealed in this process, as a destination credential would be.
    app(SecretRedactor::class)->register(UndeletableTarget::SECRET);

    $result = retentionPrune();

    expect($result->failed())->toBeTrue()
        ->and($result->failures)->toHaveCount(2)
        ->and($result->failures[0]->reason)->not->toContain(UndeletableTarget::SECRET)
        ->and(array_map(static fn (PlannedPurge $p): string => $p->point->directory, $result->purged))->toBe([$otherPoint])
        // The local files of the broken point are gone, the external rows stay live for the next purge.
        ->and(Backup::query()->where('path', 'like', "{$brokenPoint}/%")->whereNull('deleted_at')->count())->toBe(2)
        ->and(Backup::query()->where('path', 'like', "{$brokenPoint}/%")->whereNull('deleted_at')->pluck('backup_target_id')->unique()->all())
        ->toBe([BackupTargetRecord::query()->where('name', RetentionFixture::EXTERNAL)->value('id')])
        ->and(RetentionFixture::live($other))->toBe([]);

    $audit = AuditLogEntry::query()->where('action', 'backups.pruned')->where('object_id', (string) $broken->id)->sole();
    expect($audit->result)->toBe(AuditResult::Failure)
        ->and(json_encode($audit->context))->not->toContain(UndeletableTarget::SECRET)
        ->and(AuditLogEntry::query()->where('action', 'backups.pruned')->where('object_id', (string) $other->id)->sole()->result)->toBe(AuditResult::Success);
});

it('applies the same retention to every site independently', function (): void {
    $a = RetentionFixture::site();
    $b = RetentionFixture::site();
    foreach ([3, 2, 1] as $days) {
        RetentionFixture::point($a, RetentionFixture::run($a), $days);
    }
    RetentionFixture::point($b, RetentionFixture::run($b), 3);

    retentionPrune();

    expect(RetentionFixture::live($a))->toHaveCount(2)
        ->and(RetentionFixture::live($b))->toHaveCount(1);
});

it('holds the point of a run that the engine rolls back, without marking it protected, until it is reviewed', function (): void {
    $site = RetentionFixture::site();
    $run = Run::factory()->create(['site_id' => $site->id, 'type' => RunType::Update]);
    app(PipelineResolver::class)->register(RunType::Update, static fn (): Pipeline => new Pipeline([
        new ScriptedCompensableStep('backup', static function () use ($site, $run): StepOutcome {
            RetentionFixture::point($site, $run, 9);

            return StepOutcome::succeeded('punt fet');
        }, static fn (): StepOutcome => StepOutcome::succeeded('res a desfer')),
        new ScriptedCompensableStep('update', static fn (): StepOutcome => StepOutcome::failed('fatal nou'), static fn (): StepOutcome => StepOutcome::succeeded('restaurat')),
    ]));

    app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::RolledBack)
        ->and(Backup::query()->where('run_id', $run->id)->pluck('protected')->unique()->all())->toBe([false])
        ->and(retentionHolds())->toBe(["site-{$site->id}/run{$run->id}" => RetentionHold::IncidentNotReviewed->value]);
});

it('considers a site busy when its hosting account is locked by another site, in the plan and when purging', function (): void {
    $site = RetentionFixture::site();
    $point = RetentionFixture::point($site, RetentionFixture::run($site), 9);
    $neighbour = Site::factory()->create(['hosting_account_id' => $site->hosting_account_id]);
    $retention = app(RestorePointRetention::class);
    $plan = $retention->plan();
    $lease = app(SiteLockManager::class)->acquire($neighbour, 'una altra execució');

    try {
        expect(retentionHolds())->toBe([$point => RetentionHold::SiteBusy->value]);
        $result = $retention->prune($plan, AuditActor::system('tests'), 'tests');
    } finally {
        $lease->release();
    }

    expect(array_map(static fn (Site $s): int => $s->id, $result->busySites))->toBe([$site->id])
        ->and(RetentionFixture::stored($point))->toBeTrue();
});

it('stops purging a site as soon as its lock is lost, and audits what it had deleted', function (): void {
    $site = RetentionFixture::site();
    $first = RetentionFixture::point($site, RetentionFixture::run($site), 10);
    $second = RetentionFixture::point($site, RetentionFixture::run($site), 9);
    $local = app(BackupTargetRegistry::class);
    // The first delete loses the lock (as if it expired or an admin released it).
    $local->register(BackupTargetType::SiteLocal, static fn (): BackupTarget => new class implements BackupTarget
    {
        public function put(string $key, $stream): StoredObject
        {
            throw new BackupTargetException('no');
        }

        public function readStream(string $key)
        {
            throw BackupTargetException::notFound($key);
        }

        public function exists(string $key): bool
        {
            return true;
        }

        public function checksum(string $key): string
        {
            return str_repeat('0', 64);
        }

        public function delete(string $key): void
        {
            @unlink(RetentionFixture::dir(false)."/{$key}");
            $siteId = (int) substr(explode('/', $key)[0], 5);
            app(SiteLockManager::class)->forceRelease(LockLevel::Site, $siteId);
        }

        public function list(string $prefix = ''): array
        {
            return [];
        }
    });

    $result = retentionPrune();

    $audit = AuditLogEntry::query()->where('action', 'backups.pruned')->sole();
    expect($result->failed())->toBeTrue()
        ->and($result->purged)->toBe([])
        ->and(array_map(static fn (Site $s): int => $s->id, $result->lockLostSites))->toBe([$site->id])
        ->and(Backup::query()->where('site_id', $site->id)->whereNotNull('deleted_at')->count())->toBe(1)
        ->and(RetentionFixture::live($site))->toBe([$first, $second])
        ->and($audit->result)->toBe(AuditResult::Failure)
        ->and($audit->context['lock_lost'] ?? null)->toBeTrue()
        ->and($audit->context['deleted_backup_ids'] ?? null)->toHaveCount(1);
});

it('audits what it deleted before an exception stopped the purge', function (): void {
    $site = RetentionFixture::site();
    RetentionFixture::point($site, RetentionFixture::run($site), 9);
    $target = new class extends UndeletableTarget
    {
        public int $deletes = 0;

        public function delete(string $key): void
        {
            if (++$this->deletes > 1) {
                throw new RunException('El torn de l\'execució ha acabat.');
            }
        }
    };
    app(BackupTargetRegistry::class)->register(BackupTargetType::SiteLocal, static fn (): BackupTarget => $target);

    expect(fn () => retentionPrune())->toThrow(RunException::class);

    $audit = AuditLogEntry::query()->where('action', 'backups.pruned')->sole();
    expect($audit->context['deleted_backup_ids'] ?? null)->toHaveCount(1)
        ->and(Backup::query()->where('site_id', $site->id)->whereNotNull('deleted_at')->count())->toBe(1);
});

it('still deletes the copies at a destination that was deactivated or unlinked from the site', function (string $change): void {
    $site = RetentionFixture::site(external: true);
    $point = RetentionFixture::point($site, RetentionFixture::run($site), 9);
    $record = BackupTargetRecord::query()->where('name', RetentionFixture::EXTERNAL)->sole();
    $change === 'deactivated' ? $record->update(['active' => false]) : $site->backupTargets()->detach($record->id);

    $result = retentionPrune();

    expect($result->failed())->toBeFalse()
        ->and(RetentionFixture::stored($point, external: true))->toBeFalse()
        ->and(RetentionFixture::live($site))->toBe([]);
})->with(['deactivated', 'unlinked']);

it('fails clearly for a destination whose type is no longer registered', function (): void {
    $site = RetentionFixture::site();
    $record = BackupTargetRecord::factory()->create(['type' => BackupTargetType::S3, 'name' => 'antiga-s3']);
    Backup::factory()->create(['site_id' => $site->id, 'run_id' => RetentionFixture::run($site)->id, 'backup_target_id' => $record->id, 'path' => "site-{$site->id}/run1/db.gz", 'expires_at' => now()->subDay()]);

    $result = retentionPrune();

    expect($result->failures)->toHaveCount(1)
        ->and($result->failures[0]->reason)->toContain('No hi ha cap destinació de còpia registrada per al tipus «s3»');
});

it('opens a broken destination once per purge and skips the rest of its files', function (): void {
    $site = RetentionFixture::site(external: true);
    RetentionFixture::point($site, RetentionFixture::run($site), 10);
    RetentionFixture::point($site, RetentionFixture::run($site), 9);
    $opened = 0;
    app(BackupTargetRegistry::class)->register(BackupTargetType::Sftp, static function () use (&$opened): BackupTarget {
        $opened++;

        throw new BackupTargetException('No es pot connectar a la destinació.');
    });

    $result = retentionPrune();

    expect($opened)->toBe(1)
        ->and($result->failures)->toHaveCount(4)
        ->and(array_unique(array_map(static fn (PruneFailure $f): string => $f->reason, $result->failures)))->toBe(['No es pot connectar a la destinació.'])
        // The local copies went: only the external rows stay.
        ->and(Backup::query()->where('site_id', $site->id)->whereNull('deleted_at')->count())->toBe(4);
});
