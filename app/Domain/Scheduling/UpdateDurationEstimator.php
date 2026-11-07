<?php

declare(strict_types=1);

namespace App\Domain\Scheduling;

use App\Domain\Backups\BackupStep;
use App\Enums\RunStatus;
use App\Enums\RunStepKind;
use App\Enums\RunType;
use App\Enums\UpdateStrategy;
use App\Models\Run;
use App\Models\Site;
use Illuminate\Database\Query\Builder;

/**
 * How long an update of a site is expected to take (ADR-0006 §3): the median
 * of its last update runs with the same strategy that went past the plan
 * (they made a restore point: a run that found nothing to do says nothing of
 * an update's length), finished in any state. The median, not the mean: one
 * run stuck on a slow host does not keep the site out of every window. Without
 * history, the default of the strategy.
 */
final readonly class UpdateDurationEstimator
{
    /**
     * @param  array<string, int>  $defaultMinutes  by UpdateStrategy value
     */
    public function __construct(private int $historyRuns, private array $defaultMinutes) {}

    public function estimateSeconds(Site $site, UpdateStrategy $strategy): int
    {
        $durations = Run::query()
            ->where('site_id', $site->id)
            ->where('type', RunType::Update->value)
            ->where('strategy', $strategy->value)
            ->whereIn('status', [RunStatus::Succeeded->value, RunStatus::Failed->value, RunStatus::RolledBack->value])
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->whereExists(static fn (Builder $q) => $q->from('run_steps')->whereColumn('run_steps.run_id', 'runs.id')
                ->where('run_steps.kind', RunStepKind::Forward->value)->where('run_steps.name', BackupStep::NAME))
            ->orderByDesc('finished_at')
            ->limit($this->historyRuns)
            ->get(['started_at', 'finished_at'])
            ->map(static fn (Run $run): int => (int) $run->started_at?->diffInSeconds($run->finished_at, true))
            ->sort()
            ->values()
            ->all();

        if ($durations === []) {
            return ($this->defaultMinutes[$strategy->value] ?? max([0, ...array_values($this->defaultMinutes)])) * 60;
        }

        $middle = intdiv(count($durations), 2);

        return count($durations) % 2 === 1 ? $durations[$middle] : intdiv($durations[$middle - 1] + $durations[$middle], 2);
    }
}
