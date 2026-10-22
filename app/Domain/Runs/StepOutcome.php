<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use App\Enums\RunStepStatus;
use InvalidArgumentException;

/**
 * Result of a step. Output and data may contain raw remote output: the engine
 * redacts them before storing anything, so data never carries secrets to later
 * steps (they read credentials from the SecretStore themselves).
 */
final readonly class StepOutcome
{
    /**
     * @param  array<string, mixed>  $data  handed to later steps and to compensate()
     */
    public function __construct(
        public RunStepStatus $status,
        public string $summary,
        public string $output = '',
        public array $data = [],
    ) {
        if (! $status->isFinished()) {
            throw new InvalidArgumentException("A step outcome must be final, got {$status->value}.");
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function succeeded(string $summary, string $output = '', array $data = []): self
    {
        return new self(RunStepStatus::Succeeded, $summary, $output, $data);
    }

    public static function failed(string $summary, string $output = ''): self
    {
        return new self(RunStepStatus::Failed, $summary, $output);
    }

    public static function skipped(string $summary): self
    {
        return new self(RunStepStatus::Skipped, $summary);
    }
}
