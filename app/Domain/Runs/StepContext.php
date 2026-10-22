<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use App\Models\Run;

/**
 * What a step can see: its run, whether it is a dry run, the data returned by
 * the forward steps that already succeeded and, during a rollback, which step
 * failed and why.
 */
final readonly class StepContext
{
    /**
     * @param  array<string, array<string, mixed>>  $results  step name => data
     */
    public function __construct(
        public Run $run,
        public bool $dryRun,
        public array $results = [],
        public ?string $failedStep = null,
        public ?string $failureReason = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resultOf(string $step): array
    {
        return $this->results[$step] ?? [];
    }
}
