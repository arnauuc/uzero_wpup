<?php

declare(strict_types=1);

namespace App\Domain\Runs\Exceptions;

/**
 * A recorded step that the pipeline no longer has (UZEROWPUP-62): the code
 * changed between the interruption and the resume. The engine fails the run
 * loudly instead of retrying it forever.
 */
final class UnknownStep extends RunException
{
    public function __construct(public readonly string $step, bool $recovery)
    {
        parent::__construct($recovery ? "The pipeline has no recovery step named «{$step}»." : "The pipeline has no step named «{$step}».");
    }
}
