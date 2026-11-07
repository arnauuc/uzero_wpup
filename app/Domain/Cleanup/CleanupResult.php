<?php

declare(strict_types=1);

namespace App\Domain\Cleanup;

/**
 * What one retention class did (or would do, on a dry run).
 */
final readonly class CleanupResult
{
    /**
     * @param  string  $task  stable identifier (run_output, artifacts…)
     * @param  string|null  $note  why nothing was done, for the operator (no secrets)
     * @param  list<string>  $failures  operator messages, no secrets
     */
    public function __construct(
        public string $task,
        public int $count,
        public int $bytes,
        public bool $dryRun,
        public ?string $note = null,
        public array $failures = [],
    ) {}

    public function failed(): bool
    {
        return $this->failures !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['task' => $this->task, 'count' => $this->count, 'bytes' => $this->bytes, 'dry_run' => $this->dryRun, 'note' => $this->note, 'failures' => $this->failures];
    }
}
