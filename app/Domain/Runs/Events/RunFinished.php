<?php

declare(strict_types=1);

namespace App\Domain\Runs\Events;

use App\Models\Run;

/**
 * A run reached its final state (RunEngine). Listeners run synchronously,
 * after the state and its audit entry are stored.
 */
final readonly class RunFinished
{
    public function __construct(public Run $run) {}
}
