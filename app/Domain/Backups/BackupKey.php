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
    private const string SEGMENT = '[A-Za-z0-9_-][A-Za-z0-9._-]{0,127}';

    /**
     * @throws BackupTargetException
     */
    public static function valid(string $key): string
    {
        // A segment never starts with ".", so "." and ".." (and hidden files) are out.
        return strlen($key) <= 1024 && preg_match('#^'.self::SEGMENT.'(/'.self::SEGMENT.')*$#', $key) === 1
            ? $key
            : throw BackupTargetException::invalidKey($key);
    }

    /**
     * A listing prefix: "" or a valid key, optionally ending in "/".
     *
     * @throws BackupTargetException
     */
    public static function validPrefix(string $prefix): string
    {
        if ($prefix === '') {
            return '';
        }

        self::valid(rtrim($prefix, '/'));

        return $prefix;
    }
}
