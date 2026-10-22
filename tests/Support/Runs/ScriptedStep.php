<?php

declare(strict_types=1);

namespace Tests\Support\Runs;

use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use Closure;

/**
 * Test step whose behaviour is a closure.
 */
class ScriptedStep implements Step
{
    /**
     * @param  Closure(StepContext): StepOutcome  $run
     */
    public function __construct(
        private readonly string $name,
        private readonly Closure $run,
        private readonly int $timeout = 30,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeout;
    }

    public function describe(StepContext $context): string
    {
        return "faria «{$this->name}»";
    }

    public function run(StepContext $context): StepOutcome
    {
        return ($this->run)($context);
    }
}
