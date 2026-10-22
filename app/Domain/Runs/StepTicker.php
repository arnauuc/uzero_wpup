<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use Closure;

/**
 * Calls a tick every few seconds while a step runs, using SIGALRM with async
 * signals: it keeps the claim and the site lock alive and enforces the step
 * timeout by throwing from the tick. The engine also checks the elapsed time
 * after the step, so the timeout holds even where pcntl is missing.
 *
 * It takes over SIGALRM, so the queue job that runs the engine has no worker
 * timeout of its own (ExecuteRun::$timeout = 0).
 */
final class StepTicker
{
    private bool $active = false;

    public static function supported(): bool
    {
        return function_exists('pcntl_async_signals') && function_exists('pcntl_alarm');
    }

    /**
     * @param  Closure(): int  $tick  returns the seconds until the next tick (≥ 1)
     */
    public function start(int $firstTickSeconds, Closure $tick): void
    {
        if (! self::supported()) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use ($tick): void {
            if ($this->active) {
                pcntl_alarm(max(1, $tick()));
            }
        });
        $this->active = true;
        pcntl_alarm(max(1, $firstTickSeconds));
    }

    public function stop(): void
    {
        if (! $this->active) {
            return;
        }

        $this->active = false;
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
    }
}
