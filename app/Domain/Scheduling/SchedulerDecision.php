<?php

declare(strict_types=1);

namespace App\Domain\Scheduling;

/**
 * One thing a scheduler tick did (or, in a dry run, would do), for the
 * command's output and the log.
 */
final readonly class SchedulerDecision
{
    public function __construct(
        public SchedulerAction $action,
        /** Null for decisions about the whole queue (waiting, halted). */
        public ?string $site,
        public ?string $reason = null,
        public ?int $runId = null,
    ) {}

    /**
     * @return array{action: string, site: ?string, reason: ?string, run_id: ?int}
     */
    public function toArray(): array
    {
        return ['action' => $this->action->value, 'site' => $this->site, 'reason' => $this->reason, 'run_id' => $this->runId];
    }

    public function describe(): string
    {
        return ($this->site === null ? '' : "{$this->site}: ").$this->action->label()
            .($this->runId === null ? '' : " (execució #{$this->runId})")
            .($this->reason === null ? '' : " — {$this->reason}");
    }
}
