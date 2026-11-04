<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use App\Domain\Runs\Exceptions\RunWaitTimedOut;
use App\Enums\RunStatus;
use App\Models\Run;
use App\Models\RunStep;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

/**
 * Polls queued runs until they finish (--wait), reporting every step change
 * on the way (--follow). Stopping it (Ctrl-C) leaves the runs going.
 */
final class RunFollower
{
    public function __construct(private readonly int $pollMilliseconds = 1000) {}

    /**
     * With $timeoutSeconds it gives up after that long (RunWaitTimedOut; the runs
     * go on). $onStillQueued is called once if, after $queuedNoticeSeconds, some
     * run is still queued: no worker has taken it, or it waits for a lock.
     *
     * @param  list<int>  $runIds
     * @param  (Closure(Run, RunStep): void)|null  $onStep
     * @param  (Closure(list<Run>): void)|null  $onStillQueued
     * @return list<Run> the runs in their final state
     *
     * @throws RunWaitTimedOut
     */
    public function wait(array $runIds, ?Closure $onStep = null, ?int $timeoutSeconds = null, ?Closure $onStillQueued = null, int $queuedNoticeSeconds = 15): array
    {
        /** @var array<int, string> $seen step id => last reported status */
        $seen = [];
        $started = Carbon::now();
        $noticed = $onStillQueued === null;

        while (true) {
            $runs = Run::query()->whereKey($runIds)->with(['steps' => static fn ($q) => $q->orderBy('sequence')])->orderBy('id')->get();

            if ($onStep !== null) {
                foreach ($runs as $run) {
                    foreach ($run->steps as $step) {
                        if (($seen[$step->id] ?? null) !== $step->status->value) {
                            $seen[$step->id] = $step->status->value;
                            $onStep($run, $step);
                        }
                    }
                }
            }

            if ($runs->every(static fn (Run $run): bool => $run->status->isTerminal())) {
                return array_values($runs->all());
            }

            $elapsed = $started->diffInSeconds(Carbon::now());

            if (! $noticed && $elapsed >= $queuedNoticeSeconds) {
                $queued = array_values($runs->filter(static fn (Run $run): bool => $run->status === RunStatus::Queued)->all());
                if ($queued !== []) {
                    $onStillQueued($queued);
                    $noticed = true;
                }
            }

            if ($timeoutSeconds !== null && $elapsed >= $timeoutSeconds) {
                throw new RunWaitTimedOut($runIds, $timeoutSeconds);
            }

            Sleep::usleep($this->pollMilliseconds * 1000);
        }
    }

    /**
     * Exit code of a command that waited for its runs: the worst outcome wins.
     * 0 succeeded or skipped, 1 failed, 2 rolled back, 3 cancelled.
     *
     * @param  list<Run>  $runs
     */
    public static function exitCode(array $runs): int
    {
        // Severity order: failed > rolled back > cancelled > ok.
        $severity = [0 => 0, 3 => 1, 2 => 2, 1 => 3];
        $code = 0;
        foreach ($runs as $run) {
            $candidate = match ($run->status) {
                RunStatus::Failed => 1,
                RunStatus::RolledBack => 2,
                RunStatus::Cancelled => 3,
                default => 0,
            };
            $code = $severity[$candidate] > $severity[$code] ? $candidate : $code;
        }

        return $code;
    }
}
