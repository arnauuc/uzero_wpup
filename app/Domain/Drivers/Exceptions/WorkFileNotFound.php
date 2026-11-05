<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Exceptions;

/**
 * The file asked for does not exist in the work area (or is not a regular
 * file), as opposed to a failure to read it.
 */
final class WorkFileNotFound extends DriverException
{
    public static function at(string $path): self
    {
        return new self("El fitxer «{$path}» no existeix al lloc.");
    }
}
