<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * What the retention of restore points would do now (RestorePointRetention::plan()).
 */
final readonly class RetentionPlan
{
    /**
     * @param  list<PlannedPurge>  $purges
     * @param  list<RetainedPoint>  $held  points it would purge but keeps
     */
    public function __construct(
        public array $purges,
        public array $held,
    ) {}
}
