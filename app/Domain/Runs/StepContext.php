<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use App\Models\Run;

/**
 * What a step can see: its run, whether it is a dry run, the data returned by
 * the forward steps that already succeeded, during a rollback, which step
 * failed and why, and which attempt of the step this is: above 1, an earlier
 * attempt was interrupted (the engine retries a step only then, UZEROWPUP-62).
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
        public int $attempt = 1,
    ) {}

    /** An earlier attempt of this step was interrupted: the run was resumed. */
    public function resumed(): bool
    {
        return $this->attempt > 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function resultOf(string $step): array
    {
        return $this->results[$step] ?? [];
    }
}
