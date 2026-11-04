<?php

declare(strict_types=1);

namespace App\Domain\Drivers;

use App\Domain\Drivers\Exceptions\DriverException;

/**
 * Paths inside the tool's work area on a site (the store of restore points,
 * UZEROWPUP-40): relative paths of segments made of letters, digits, "._-",
 * separated by "/", with no empty, "." or ".." segment and nothing hidden.
 *
 * Wider than RemoteFileName (which stays a plain name for every existing
 * caller) so that every BackupKey under a store root fits, and every
 * RemoteFileName is also a WorkPath (an adopted dump keeps its name).
 */
final class WorkPath
{
    private const string SEGMENT = '[A-Za-z0-9_-][A-Za-z0-9._-]{0,200}';

    private const int MAX_LENGTH = 2048;

    /**
     * @throws DriverException
     */
    public static function valid(string $path): string
    {
        // A segment never starts with ".", so "." and ".." (and hidden files) are out.
        return strlen($path) <= self::MAX_LENGTH && preg_match('#^'.self::SEGMENT.'(/'.self::SEGMENT.')*$#D', $path) === 1
            ? $path
            : throw new DriverException(sprintf('Ruta remota de treball no vàlida: «%s».', mb_substr($path, 0, 200)));
    }
}
