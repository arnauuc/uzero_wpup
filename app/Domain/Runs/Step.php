<?php

declare(strict_types=1);

namespace App\Domain\Runs;

/**
 * One step of a run. Steps are idempotent: after a crash the engine runs an
 * interrupted step again, so running it twice must not duplicate its effects.
 */
interface Step
{
    /**
     * Stable key stored in run_steps.name; resuming relies on it.
     */
    public function name(): string;

    public function timeoutSeconds(): int;

    /**
     * What the step would do, for --dry-run (operator-facing, Catalan).
     */
    public function describe(StepContext $context): string;

    public function run(StepContext $context): StepOutcome;
}
