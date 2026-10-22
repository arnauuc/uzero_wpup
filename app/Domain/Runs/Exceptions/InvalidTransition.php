<?php

declare(strict_types=1);

namespace App\Domain\Runs\Exceptions;

use App\Enums\RunStatus;
use App\Enums\RunStepStatus;

final class InvalidTransition extends RunException
{
    public static function run(int $runId, RunStatus $from, RunStatus $to): self
    {
        return new self("Run {$runId} cannot go from {$from->value} to {$to->value}.");
    }

    public static function step(int $stepId, RunStepStatus $from, RunStepStatus $to): self
    {
        return new self("Run step {$stepId} cannot go from {$from->value} to {$to->value}.");
    }
}
