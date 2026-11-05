<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * Operator-readable sizes (kB / MB / GB) for backup output.
 */
final class ByteSize
{
    public static function human(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_073_741_824 => sprintf('%.1f GB', $bytes / 1_073_741_824),
            $bytes >= 1_048_576 => sprintf('%.1f MB', $bytes / 1_048_576),
            default => sprintf('%.1f kB', $bytes / 1024),
        };
    }
}
