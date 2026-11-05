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
 * panel). Its restore point stops being protected (ADR-0009): from then on the
 * retention treats it like any other (7 days, at most 2 per site).
 */
final readonly class RunAcknowledgement
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Files of the run's restore point that are protected now.
     */
    public function protectedBackups(Run $run): int
    {
        return Backup::query()->where('run_id', $run->id)->whereNull('deleted_at')->where('protected', true)->count();
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
     * @return int|null files whose protection was lifted; null if it was already reviewed
     *
     * @throws RunNotAcknowledgeable
     */
    public function acknowledge(Run $run, User $user, AuditActor $actor): ?int
    {
        $unprotected = DB::transaction(function () use ($run, $user): ?int {
            $current = Run::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->assertAcknowledgeable($current);
            if ($current->reviewed_at !== null) {
                return null;
            }

            $current->forceFill(['reviewed_at' => CarbonImmutable::now(), 'reviewed_by' => $user->id])->save();

            return Backup::query()->where('run_id', $run->id)->whereNull('deleted_at')->where('protected', true)->update(['protected' => false]);
        });
        $run->refresh();

        if ($unprotected !== null) {
            $this->audit->record($actor, 'run.acknowledged', AuditResult::Success, 'run', $run->id, [
                'status' => $run->status->value,
                'unprotected_backups' => $unprotected,
            ]);
        }

        return $unprotected;
    }
}
