<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Models\Site;

/**
 * A restore point the retention purges, and why.
 */
final readonly class PlannedPurge
{
    public function __construct(
        public Site $site,
        public StoredRestorePoint $point,
        public PurgeReason $reason,
    ) {}
}
