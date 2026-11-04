<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\RemoteFileName;

/**
 * Names of the files a restore point leaves in the site's work directory: a
 * configured pattern with the run label and a random suffix, validated with the
 * same rule as the drivers.
 */
final class BackupFileName
{
    /**
     * @param  string  $pattern  sprintf() pattern, label and suffix
     *
     * @throws BackupFailed
     */
    public static function make(string $pattern, string $label): string
    {
        $name = sprintf($pattern, $label, bin2hex(random_bytes(4)));

        try {
            return trim($label) === '' ? throw BackupFailed::invalidFileName($name) : RemoteFileName::valid($name);
        } catch (DriverException) {
            throw BackupFailed::invalidFileName($name);
        }
    }
}
