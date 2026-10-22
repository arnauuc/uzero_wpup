<?php

declare(strict_types=1);

namespace App\Domain\Runs\Concurrency;

use App\Domain\Runs\Exceptions\RunException;

/**
 * Every slot of a level is taken: the run waits in the queue (the job is
 * released, it does not fail nor spend an attempt).
 */
final class ConcurrencyLimitReached extends RunException
{
    public function __construct(public readonly string $level)
    {
        parent::__construct("Concurrency limit reached ({$level}).");
    }
}
