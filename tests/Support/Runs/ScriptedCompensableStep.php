<?php

declare(strict_types=1);

namespace Tests\Support\Runs;

use App\Domain\Runs\CompensableStep;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use Closure;

final class ScriptedCompensableStep extends ScriptedStep implements CompensableStep
{
    /**
     * @param  Closure(StepContext): StepOutcome  $run
     * @param  Closure(StepContext): StepOutcome  $compensate
     */
    public function __construct(string $name, Closure $run, private readonly Closure $compensate, int $timeout = 30)
    {
        parent::__construct($name, $run, $timeout);
    }

    public function compensate(StepContext $context): StepOutcome
    {
        return ($this->compensate)($context);
    }
}
