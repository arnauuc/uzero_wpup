<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Enums\RunStatus;

/**
 * One site in the output of `wpup plan`.
 */
final readonly class PlanRow
{
    public function __construct(
        public string $site,
        public string $url,
        public int $runId,
        public RunStatus $runStatus,
        public ?string $failure,
        public ?UpdatePlan $plan,
        /** Whether the site is blocked now (sites.blocked_reason), null if the run has no site. */
        public ?bool $blocked = null,
    ) {}

    /**
     * Whether the row has a plan the operator can act on.
     */
    public function hasPlan(): bool
    {
        return $this->plan !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'site' => $this->site,
            'url' => $this->url,
            'run_id' => $this->runId,
            'status' => $this->runStatus->value,
            'failure' => $this->failure,
            'blocked' => $this->blocked,
            ...($this->plan === null ? ['plan' => null] : ['plan' => [
                ...$this->plan->toArray(),
                'updates' => $this->plan->updateCount(),
                'skips' => $this->plan->skipCount(),
            ]]),
        ];
    }
}
