<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Runs\Exceptions\RunNotAcknowledgeable;
use App\Enums\AuditResult;
use App\Models\Backup;
use App\Models\Run;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * An operator marks a finished run as reviewed (`wpup run:ack`, later the
 * panel). The retention holds the restore point of a reverted or failed run
 * until then (RestorePointRetention::isIncident()); from then on it is treated
 * like any other, and it lasts at least `backups.retention_days` from the
 * review (ADR-0009). A point protected by hand stays protected.
 */
final readonly class RunAcknowledgement
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Live files of the run's restore point.
     */
    public function backups(Run $run): int
    {
        return Backup::query()->where('run_id', $run->id)->whereNull('deleted_at')->count();
    }

    /**
     * @throws RunNotAcknowledgeable
     */
    public function assertAcknowledgeable(Run $run): void
    {
        if (! $run->status->isTerminal()) {
            throw RunNotAcknowledgeable::unfinished($run->id, $run->status->value);
        }
    }

    /**
     * @return int|null files whose expiry was extended; null if it was already reviewed
     *
     * @throws RunNotAcknowledgeable
     */
    public function acknowledge(Run $run, User $user, AuditActor $actor): ?int
    {
        $extended = DB::transaction(function () use ($run, $user): ?int {
            $current = Run::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->assertAcknowledgeable($current);
            if ($current->reviewed_at !== null) {
                return null;
            }

            $now = CarbonImmutable::now();
            $current->forceFill(['reviewed_at' => $now, 'reviewed_by' => $user->id])->save();
            $expiresAt = $now->addDays(config()->integer('wpup.backups.retention_days'));

            // max(current expiry, review + retention): never shortened.
            return Backup::query()->where('run_id', $run->id)->whereNull('deleted_at')
                // A file without expiry is kept as it is.
                ->where('expires_at', '<', $expiresAt)
                ->update(['expires_at' => $expiresAt]);
        });
        $run->refresh();

        if ($extended !== null) {
            $this->audit->record($actor, 'run.acknowledged', AuditResult::Success, 'run', $run->id, [
                'status' => $run->status->value,
                'extended_backups' => $extended,
            ]);
        }

        return $extended;
    }
}
