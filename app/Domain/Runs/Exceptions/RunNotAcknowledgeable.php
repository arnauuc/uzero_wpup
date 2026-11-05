<?php

declare(strict_types=1);

namespace App\Domain\Runs\Exceptions;

use DomainException;

/**
 * `run:ack` on a run that has not finished: there is nothing to review yet.
 */
final class RunNotAcknowledgeable extends DomainException
{
    public static function unfinished(int $runId, string $status): self
    {
        return new self("L'execució #{$runId} encara no ha acabat ({$status}): no es pot marcar com a revisada.");
    }
}
