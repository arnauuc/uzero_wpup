<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Models\Site;

/**
 * A restore point the retention would purge ($wouldBe) but keeps ($hold).
 */
final readonly class RetainedPoint
{
    public function __construct(
        public Site $site,
        public StoredRestorePoint $point,
        public PurgeReason $wouldBe,
        public RetentionHold $hold,
    ) {}
}
