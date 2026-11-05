<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Models\Run;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What RestorePointRetention reads once per plan.
 */
final readonly class RetentionContext
{
    /**
     * @param  list<int>  $localTargetIds  site-local destinations
     * @param  Collection<int, Run>  $runs  runs of the points, by id
     * @param  list<int>  $sitesWithUnfinishedRun
     * @param  list<int>  $parentRunIds  runs an unfinished run was launched from
     */
    public function __construct(
        public CarbonImmutable $now,
        public array $localTargetIds,
        public Collection $runs,
        public array $sitesWithUnfinishedRun,
        public array $parentRunIds,
    ) {}
}
