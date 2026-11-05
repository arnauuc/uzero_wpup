<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * Why the retention of restore points (ADR-0009) would purge a point.
 */
enum PurgeReason: string
{
    /** Its expiry (7 days after it was made) has passed. */
    case Expired = 'expired';

    /** The site has more points than `backups.max_per_site`: it is the oldest. */
    case OverCap = 'over_cap';

    public function label(): string
    {
        return match ($this) {
            self::Expired => 'caducat',
            self::OverCap => 'supera el màxim per lloc',
        };
    }
}
