<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use App\Domain\Runs\Exceptions\RunException;
use InvalidArgumentException;

/**
 * Ordered steps of a run. It must be rebuilt identically for the same run, since
 * a resumed run maps its stored rows back to steps by name.
 */
final readonly class Pipeline
{
    /** @var array<string, Step> */
    private array $byName;

    /**
     * @param  list<Step>  $steps
     */
    public function __construct(public array $steps)
    {
        if ($steps === []) {
            throw new InvalidArgumentException('A pipeline needs at least one step.');
        }

        $byName = [];
        foreach ($steps as $step) {
            if (isset($byName[$step->name()])) {
                throw new InvalidArgumentException("Duplicated step name: {$step->name()}.");
            }
            $byName[$step->name()] = $step;
        }
        $this->byName = $byName;
    }

    public function get(string $name): Step
    {
        return $this->byName[$name] ?? throw new RunException("The pipeline has no step named «{$name}».");
    }
}
