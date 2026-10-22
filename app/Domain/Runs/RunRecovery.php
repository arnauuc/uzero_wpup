<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Enums\AuditResult;
use App\Enums\RunStatus;
use App\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * Watchdog (UZEROWPUP-21): a run marked running or rolling back whose claim
 * expired lost its worker. It is marked interrupted and handed back to the
 * engine, which resumes it or rolls it back. Nothing is left half-done quietly.
 */
final readonly class RunRecovery
{
    public function __construct(
        private RunClaim $claims,
        private AuditLogger $audit,
    ) {}

    /**
     * @return list<int> interrupted runs to hand back to the engine
     */
    public function sweep(): array
    {
        foreach ($this->ids(RunStatus::Running, RunStatus::RollingBack) as $id) {
            if (! $this->claims->isClaimed($id)) {
                $this->interrupt($id);
            }
        }

        return $this->ids(RunStatus::Interrupted);
    }

    /**
     * @return list<int>
     */
    private function ids(RunStatus ...$statuses): array
    {
        return array_values(Run::query()
            ->whereIn('status', array_map(static fn (RunStatus $status): string => $status->value, $statuses))
            ->orderBy('id')
            ->get(['id'])
            ->map(static fn (Run $run): int => $run->id)
            ->all());
    }

    private function interrupt(int $id): void
    {
        $interrupted = DB::transaction(function () use ($id): bool {
            $run = Run::query()->whereKey($id)->lockForUpdate()->first();

            // Re-checked under the row lock: the worker may have finished meanwhile.
            if ($run === null || ! $run->status->isActive() || $this->claims->isClaimed($id)) {
                return false;
            }

            $run->forceFill(['status' => RunStatus::Interrupted])->save();

            return true;
        });

        if ($interrupted) {
            $this->audit->record(AuditActor::system('vigilant d\'execucions'), 'run.interrupted', AuditResult::Failure, 'run', $id);
        }
    }
}
