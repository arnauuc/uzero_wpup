<?php

declare(strict_types=1);

namespace App\Domain\Runs\Exceptions;

/**
 * The caller stopped waiting; the runs themselves go on (RunFollower::wait).
 */
final class RunWaitTimedOut extends RunException
{
    /**
     * @param  list<int>  $runIds
     */
    public function __construct(public readonly array $runIds, int $seconds)
    {
        parent::__construct(sprintf('Stopped waiting for runs %s after %d s; they keep going.', implode(', ', $runIds), $seconds));
    }
}
