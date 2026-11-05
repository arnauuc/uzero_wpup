<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * A directory of the tool server only its user can enter (0700), for the
 * temporary copies of restore points. Never sys_get_temp_dir(): /tmp may be a
 * tmpfs (Debian 13), too small for an archive and shared with other users.
 */
final class PrivateDirectory
{
    /**
     * Creates $path (and its parents) if missing and makes it 0700.
     *
     * @throws RuntimeException
     */
    public static function ensure(string $path): string
    {
        if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException("No s'ha pogut crear el directori privat «{$path}».");
        }
        if (! chmod($path, 0700)) {
            throw new RuntimeException("No s'han pogut restringir els permisos de «{$path}».");
        }

        return $path;
    }
}
