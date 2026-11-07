<?php

declare(strict_types=1);

namespace App\Console\Commands\Runs;

use App\Console\ChangesState;
use App\Console\WpupCommand;
use App\Domain\Scheduling\SchedulerAction;
use App\Domain\Scheduling\SchedulerDecision;
use App\Domain\Scheduling\UpdateScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Scheduler-only tick of the scheduled updates (ADR-0006, UZEROWPUP-129), every
 * minute; not reachable through the `wpup` binary. Its runs are audited as
 * they are queued (run.queued, as `sistema (scheduler)`).
 */
final class EngineScheduleUpdates extends Command implements ChangesState
{
    protected $signature = 'engine:schedule-updates
        {--dry-run : Mostra què es faria sense fer-ho}
        {--json : Sortida en JSON, per a scripts}';

    protected $description = 'Planificador: encua i comença les actualitzacions programades, d\'una en una (ADR-0006)';

    public function handle(UpdateScheduler $scheduler): int
    {
        $dryRun = $this->option('dry-run') === true;
        // withoutOverlapping() covers the scheduler; this covers a manual run beside it.
        $lock = Cache::lock('wpup:scheduler:tick', 300);
        if (! $dryRun && ! $lock->get()) {
            $this->warn('Ja hi ha un altre torn del planificador en marxa.');

            return self::SUCCESS;
        }

        try {
            $decisions = $scheduler->tick(CarbonImmutable::now(), $dryRun);
        } finally {
            if (! $dryRun) {
                $lock->release();
            }
        }

        foreach ($decisions as $decision) {
            // Waiting is the normal state of most ticks: not logged.
            if (! $dryRun && $decision->action !== SchedulerAction::Waiting) {
                Log::info('Scheduler: '.$decision->describe(), $decision->toArray());
            }
        }
        if ($this->option('json') === true) {
            $this->line((string) json_encode(['dry_run' => $dryRun, 'decisions' => array_map(static fn (SchedulerDecision $d): array => $d->toArray(), $decisions)], WpupCommand::JSON_FLAGS));

            return self::SUCCESS;
        }
        foreach ($decisions as $decision) {
            $this->line(($dryRun ? '[dry-run] ' : '').$decision->describe());
        }

        return self::SUCCESS;
    }
}
