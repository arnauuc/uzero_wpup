<?php

declare(strict_types=1);

namespace App\Domain\Runs;

/**
 * A step whose effects can be undone. When the run fails, compensate() runs in
 * reverse order for every compensable step that succeeded or failed (a failed
 * step may have applied part of its changes). It is idempotent too.
 */
interface CompensableStep extends Step
{
    public function compensate(StepContext $context): StepOutcome;
}
