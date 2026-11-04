<?php

declare(strict_types=1);

namespace App\Domain\Drivers;

use App\Domain\Drivers\Exceptions\DriverException;

/**
 * Names of the files the tool keeps on a site: a plain name, never a path.
 * Every driver and every backup service checks them the same way.
 */
final class RemoteFileName
{
    /**
     * @throws DriverException
     */
    public static function valid(string $name): string
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,200}$/', $name) === 1 && ! str_contains($name, '..')
            ? $name
            : throw new DriverException("Nom de fitxer remot no vàlid: «{$name}».");
    }
}
