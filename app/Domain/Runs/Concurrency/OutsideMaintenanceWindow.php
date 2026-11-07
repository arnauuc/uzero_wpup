<?php

declare(strict_types=1);

namespace App\Domain\Runs\Concurrency;

use App\Domain\Runs\Exceptions\RunException;
use Carbon\CarbonImmutable;

/**
 * The site's maintenance window is closed, or too little of it is left: the
 * run waits in the queue for the next opening (ADR-0006 §3). The job is
 * released, it does not fail nor spend an attempt.
 */
final class OutsideMaintenanceWindow extends RunException
{
    public function __construct(public readonly CarbonImmutable $opensAt, string $window)
    {
        parent::__construct("Outside the maintenance window ({$window}); next opening at {$opensAt->toIso8601String()}.");
    }
}
