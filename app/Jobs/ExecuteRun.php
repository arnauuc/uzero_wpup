<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Locks\LockedException;
use App\Domain\Runs\Concurrency\ConcurrencyLimitReached;
use App\Domain\Runs\Concurrency\OutsideMaintenanceWindow;
use App\Domain\Runs\Exceptions\RunAlreadyClaimed;
use App\Domain\Runs\RunEngine;
use App\Domain\Secrets\SecretRedactor;
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

    // Released (not failed) while waiting for a slot or a lock; any exception fails it at once.
    public int $tries = 0;

    public int $maxExceptions = 1;

    public function __construct(public readonly int $runId) {}

    public function handle(RunEngine $engine, SecretRedactor $redactor): void
    {
        $run = Run::query()->find($this->runId);

        if ($run === null) {
            return;
        }

        try {
            $engine->execute($run);
        } catch (RunAlreadyClaimed) {
            Log::info('Run already being executed by another worker.', ['run' => $this->runId]);
        } catch (ConcurrencyLimitReached $e) {
            // Waiting for a slot is normal (ADR-0006: one update at a time): no attempt is spent.
            $this->release(self::seconds('wpup.concurrency.retry_seconds'));
        } catch (OutsideMaintenanceWindow $e) {
            // ADR-0006: the update waits for its site's window; checked again at least every window.recheck_seconds.
            $wait = max(1, min((int) now()->diffInSeconds($e->opensAt, true), self::seconds('wpup.updates.window.recheck_seconds')));
            Log::info('Run waits for its maintenance window.', ['run' => $this->runId, 'opens_at' => $e->opensAt->toIso8601String()]);
            $this->release($wait);
        } catch (LockedException $e) {
            Log::info('Run waits for a busy lock.', ['run' => $this->runId, 'lock' => $e->getMessage()]);
            $this->release(self::seconds('wpup.runs.lock_retry_seconds'));
        } finally {
            // The worker lives on: forget the secrets this run revealed, so they
            // do not pile up in its memory (the redactor is a process singleton).
            $redactor->reset();
        }
    }

    private static function seconds(string $key): int
    {
        $seconds = config($key);

        return is_int($seconds) ? $seconds : 60;
    }
}
