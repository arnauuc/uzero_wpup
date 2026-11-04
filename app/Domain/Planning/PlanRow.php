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
    ) {}

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
            'blocked' => $this->plan?->blockedReason !== null,
            ...($this->plan === null ? ['plan' => null] : ['plan' => [
                ...$this->plan->toArray(),
                'updates' => $this->plan->updateCount(),
                'skips' => $this->plan->skipCount(),
            ]]),
        ];
    }
}
