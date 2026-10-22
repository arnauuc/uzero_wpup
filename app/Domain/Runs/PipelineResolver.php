<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use App\Domain\Runs\Exceptions\RunException;
use App\Enums\RunType;
use App\Models\Run;
use Closure;

/**
 * Builds the pipeline of a run from its type. Each run type registers its
 * factory when it is implemented (inventory, update, rollback…).
 */
final class PipelineResolver
{
    /** @var array<string, Closure(Run): Pipeline> */
    private array $factories = [];

    /**
     * @param  Closure(Run): Pipeline  $factory
     */
    public function register(RunType $type, Closure $factory): void
    {
        $this->factories[$type->value] = $factory;
    }

    public function resolve(Run $run): Pipeline
    {
        $factory = $this->factories[$run->type->value]
            ?? throw new RunException("No pipeline is registered for runs of type «{$run->type->value}».");

        return $factory($run);
    }
}
