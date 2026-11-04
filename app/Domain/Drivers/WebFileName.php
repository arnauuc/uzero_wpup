<?php

declare(strict_types=1);

namespace App\Domain\Drivers;

use App\Domain\Drivers\Exceptions\DriverException;

/**
 * Names accepted by SiteDriver::putWebFile/removeWebFile: a bare .php file name
 * in the WordPress root, never a path. Every driver checks it the same way.
 */
final class WebFileName
{
    /**
     * @throws DriverException
     */
    public static function valid(string $name): string
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*\.php$/', $name) === 1
            ? $name
            : throw new DriverException("Nom de fitxer web no vàlid: {$name}");
    }
}
