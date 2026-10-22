<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Locks\LockedException;
use App\Domain\Runs\Exceptions\RunAlreadyClaimed;
use App\Domain\Runs\RunEngine;
use App\Models\Run;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Queue entry point of the run engine.
 */
final class ExecuteRun implements ShouldQueue
{
    use Queueable;

    // The engine enforces step and run timeouts itself and takes over SIGALRM.
    public int $timeout = 0;

    // Released (not failed) while the site is locked; any exception fails it at once.
    public int $tries = 0;

    public int $maxExceptions = 1;

    public function __construct(public readonly int $runId) {}

    public function handle(RunEngine $engine): void
    {
        $run = Run::query()->find($this->runId);

        if ($run === null) {
            return;
        }

        try {
            $engine->execute($run);
        } catch (RunAlreadyClaimed) {
            Log::info('Run already being executed by another worker.', ['run' => $this->runId]);
        } catch (LockedException $e) {
            Log::info('Run waits for a busy lock.', ['run' => $this->runId, 'lock' => $e->getMessage()]);
            $this->release(self::retrySeconds());
        }
    }

    private static function retrySeconds(): int
    {
        $seconds = config('wpup.runs.lock_retry_seconds');

        return is_int($seconds) ? $seconds : 60;
    }
}
