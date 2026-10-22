<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Models\RunStep;
use Closure;
use Illuminate\Support\Sleep;

/**
 * Polls queued runs until they finish (--wait), reporting every step change
 * on the way (--follow). Stopping it (Ctrl-C) leaves the runs going.
 */
final class RunFollower
{
    public function __construct(private readonly int $pollMilliseconds = 1000) {}

    /**
     * @param  list<int>  $runIds
     * @param  (Closure(Run, RunStep): void)|null  $onStep
     * @return list<Run> the runs in their final state
     */
    public function wait(array $runIds, ?Closure $onStep = null): array
    {
        /** @var array<int, string> $seen step id => last reported status */
        $seen = [];

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
