<?php

declare(strict_types=1);

namespace App\Domain\ToolBackup;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Keys of the tool's backups at their destination:
 * `tool/wpup-YYYYmmdd-HHMMSS.tar.age`, lowercase (BackupTarget rules), with the
 * time of the copy in the application timezone. Retention reads the time back
 * from the key, never from the destination's metadata.
 */
final class ToolBackupKey
{
    public const string PREFIX = 'tool/';

    private const string PATTERN = '/^tool\/wpup-(\d{8}-\d{6})\.tar\.age$/';

    /** Uploads waiting for their SHA-256 check: never a final key, never kept. */
    public const string PENDING_PREFIX = 'tool/pending/';

    /**
     * Where a copy is uploaded before its checksum is checked; unique per run.
     */
    public static function pendingFor(DateTimeInterface $at): string
    {
        return self::PENDING_PREFIX.substr(self::for($at), strlen(self::PREFIX), -strlen('.tar.age')).'-'.bin2hex(random_bytes(4)).'.tar.age';
    }

    public static function for(DateTimeInterface $at): string
    {
        return self::PREFIX.'wpup-'.CarbonImmutable::instance($at)->setTimezone(self::timezone())->format('Ymd-His').'.tar.age';
    }

    /**
     * The time of the copy, or null when $key is not one of these keys (it is
     * then left alone: retention never deletes what it did not create).
     */
    public static function timeOf(string $key): ?CarbonImmutable
    {
        if (preg_match(self::PATTERN, $key, $m) !== 1) {
            return null;
        }
        $at = CarbonImmutable::createFromFormat('!Ymd-His', $m[1], self::timezone());

        // createFromFormat() rolls invalid dates over (20261332): refuse them.
        return $at instanceof CarbonImmutable && $at->format('Ymd-His') === $m[1] ? $at : null;
    }

    private static function timezone(): string
    {
        $timezone = config('app.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }
}
