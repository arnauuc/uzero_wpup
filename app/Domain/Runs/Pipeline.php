<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use App\Domain\Runs\Exceptions\UnknownStep;
use InvalidArgumentException;

/**
 * Ordered steps of a run. It must be rebuilt identically for the same run, since
 * a resumed run maps its stored rows back to steps by name.
 *
 * A pipeline may grow as its run learns more (UZEROWPUP-60): the resolver is
 * asked again after each step named in $growsAfter (and when the run resumes),
 * and a pipeline built from the results stored so far may add steps at its end
 * (the update run knows its component updates only once the plan step ran). It
 * never changes, removes or reorders a step that is already recorded: the
 * engine fails the run if it does.
 *
 * When a forward step fails, the engine follows its OnFailure policy: the
 * compensations (default), the recovery branch, or nothing.
 */
final readonly class Pipeline
{
    /** @var array<string, Step> */
    private array $byName;

    /** @var array<string, Step> */
    private array $recoveryByName;

    /**
     * @param  list<Step>  $steps
     * @param  list<Step>  $recovery  run in order after a failure of a step whose policy is Recover
     * @param  array<string, OnFailure>  $onFailure  step name => policy; Compensate when absent
     * @param  list<string>  $growsAfter  steps whose results the pipeline is built from
     */
    public function __construct(public array $steps, public array $recovery = [], private array $onFailure = [], private array $growsAfter = [])
    {
        if ($steps === []) {
            throw new InvalidArgumentException('A pipeline needs at least one step.');
        }

        $this->byName = self::index($steps);
        $this->recoveryByName = self::index($recovery);

        foreach ($onFailure as $name => $policy) {
            if (! isset($this->byName[$name])) {
                throw new InvalidArgumentException("A failure policy names an unknown step: {$name}.");
            }
            if ($policy === OnFailure::Recover && $recovery === []) {
                throw new InvalidArgumentException("Step {$name} recovers on failure, but the pipeline has no recovery steps.");
            }
        }
        foreach ($growsAfter as $name) {
            if (! isset($this->byName[$name])) {
                throw new InvalidArgumentException("The pipeline grows after an unknown step: {$name}.");
            }
        }
    }

    /**
     * Whether the pipeline may change once some of its steps ran.
     */
    public function grows(): bool
    {
        return $this->growsAfter !== [];
    }

    /**
     * Whether the pipeline must be resolved again once $name finished.
     */
    public function growsAfter(string $name): bool
    {
        return in_array($name, $this->growsAfter, true);
    }

    /**
     * @throws UnknownStep
     */
    public function get(string $name): Step
    {
        return $this->byName[$name] ?? throw new UnknownStep($name, false);
    }

    /**
     * @throws UnknownStep
     */
    public function recoveryStep(string $name): Step
    {
        return $this->recoveryByName[$name] ?? throw new UnknownStep($name, true);
    }

    public function onFailure(string $name): OnFailure
    {
        return $this->onFailure[$name] ?? OnFailure::Compensate;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (Step $step): string => $step->name(), $this->steps);
    }

    /**
     * @param  list<Step>  $steps
     * @return array<string, Step>
     */
    private static function index(array $steps): array
    {
        $byName = [];
        foreach ($steps as $step) {
            if (isset($byName[$step->name()])) {
                throw new InvalidArgumentException("Duplicated step name: {$step->name()}.");
            }
            $byName[$step->name()] = $step;
        }

        return $byName;
    }
}
