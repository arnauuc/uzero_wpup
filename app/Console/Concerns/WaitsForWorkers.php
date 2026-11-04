<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Domain\Runs\Exceptions\RunWaitTimedOut;
use App\Domain\Runs\RunFollower;
use App\Models\Run;

/**
 * For commands whose work needs the KEK private key and so runs on the workers
 * (ADR-0011): they wait for the run, but never forever and never in silence.
 */
trait WaitsForWorkers
{
    /**
     * @param  list<Run>  $runs
     * @return list<Run>|null the final runs, or null when the wait timed out (already reported)
     */
    protected function waitForWorkers(array $runs, int $stepTimeoutSeconds): ?array
    {
        $config = config('wpup.credential_operations');
        $margin = is_array($config) && is_int($config['wait_margin_seconds'] ?? null) ? $config['wait_margin_seconds'] : 600;
        $notice = is_array($config) && is_int($config['queued_notice_seconds'] ?? null) ? $config['queued_notice_seconds'] : 15;

        try {
            return app(RunFollower::class)->wait(
                array_map(static fn (Run $run): int => $run->id, $runs),
                timeoutSeconds: $stepTimeoutSeconds + $margin,
                onStillQueued: function (array $queued): void {
                    $ids = implode(', ', array_map(static fn (Run $run): string => "#{$run->id}", $queued));
                    $this->warn("Encara en cua ({$ids}): o no hi ha cap worker actiu (comprova Horizon) o espera un lock, per exemple una actualització del mateix compte.");
                },
                queuedNoticeSeconds: $notice,
            );
        } catch (RunWaitTimedOut $e) {
            $ids = implode(', ', array_map(static fn (int $id): string => "#{$id}", $e->runIds));
            $this->error("Deixo d'esperar: l'execució {$ids} continua. Consulta-la amb `wpup runs`.");

            return null;
        }
    }
}
