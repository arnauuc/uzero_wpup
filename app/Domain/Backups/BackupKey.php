<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * Keys every BackupTarget accepts: relative paths of segments made of letters,
 * digits, "._-", separated by "/", with no empty, "." or ".." segment. The same
 * rule for every destination keeps restore points portable between them and
 * stops path traversal.
 */
final class BackupKey
{
    // Part of the key format shared by every destination (portability between
    // them), not a tunable limit: changing it would strand existing objects.
    private const string SEGMENT = '[A-Za-z0-9_-][A-Za-z0-9._-]{0,127}';

    private const int MAX_LENGTH = 1024;

    /**
     * @throws BackupTargetException
     */
    public static function valid(string $key): string
    {
        // A segment never starts with ".", so "." and ".." (and hidden files) are out.
        return strlen($key) <= self::MAX_LENGTH && preg_match('#^'.self::SEGMENT.'(/'.self::SEGMENT.')*$#D', $key) === 1
            ? $key
            : throw BackupTargetException::invalidKey($key);
    }

    /**
     * A listing prefix: "" or a directory ending in exactly one "/", so that
     * "site-1/" never matches "site-10/…".
     *
     * @throws BackupTargetException
     */
    public static function validPrefix(string $prefix): string
    {
        if ($prefix === '') {
            return '';
        }

        if (! str_ends_with($prefix, '/')) {
            throw BackupTargetException::invalidKey($prefix);
        }

        self::valid(substr($prefix, 0, -1));

        return $prefix;
    }
}
