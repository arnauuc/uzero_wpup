<?php

declare(strict_types=1);

namespace App\Domain\Cleanup;

use App\Domain\Audit\AuditActor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The daily cleanup (ADR-0009 §4): runs every retention class on its own, so
 * one failing does not stop the rest. audit_log is deliberately not a class:
 * it is never purged. Restore points have their own scheduled purge
 * (engine:prune-restore-points) and are not repeated here.
 */
final readonly class CleanupService
{
    /**
     * @param  list<CleanupTask>  $tasks
     */
    public function __construct(private array $tasks) {}

    /**
     * @return list<CleanupResult>
     */
    public function run(AuditActor $actor, CarbonImmutable $now, bool $dryRun): array
    {
        $results = [];
        foreach ($this->tasks as $task) {
            try {
                $results[] = $task->run($actor, $now, $dryRun);
            } catch (Throwable $e) {
                Log::error("Cleanup task {$task->name()} failed: ".$e::class);
                $results[] = new CleanupResult($task->name(), 0, 0, $dryRun, null, ["La neteja «{$task->name()}» ha fallat."]);
            }
        }

        return $results;
    }
}
