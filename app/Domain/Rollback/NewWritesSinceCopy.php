<?php

declare(strict_types=1);

namespace App\Domain\Rollback;

use App\Domain\Backups\NewWrites;
use RuntimeException;

/**
 * The check right before the import found (or could not rule out) new
 * writes to the orders or users (UZEROWPUP-59): the database is not touched.
 */
final class NewWritesSinceCopy extends RuntimeException
{
    public function __construct(public readonly NewWrites $writes)
    {
        parent::__construct('New writes since the copy.');
    }
}
