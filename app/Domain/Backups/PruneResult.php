<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Models\Site;

/**
 * What a purge of restore points did.
 */
final readonly class PruneResult
{
    /**
     * @param  list<PlannedPurge>  $purged  points whose files were deleted at every destination
     * @param  list<PruneFailure>  $failures  files that are still there (their point is not in $purged)
     * @param  list<Site>  $busySites  sites left for the next purge: their lock was taken
     * @param  list<Site>  $lockLostSites  sites whose purge stopped halfway: their lock was lost
     */
    public function __construct(
        public array $purged = [],
        public array $failures = [],
        public array $busySites = [],
        public array $lockLostSites = [],
    ) {}

    /**
     * @param  list<self>  $results
     */
    public static function merge(array $results): self
    {
        $purged = $failures = $busy = $lost = [];
        foreach ($results as $result) {
            array_push($purged, ...$result->purged);
            array_push($failures, ...$result->failures);
            array_push($busy, ...$result->busySites);
            array_push($lost, ...$result->lockLostSites);
        }

        return new self($purged, $failures, $busy, $lost);
    }

    public function failed(): bool
    {
        return $this->failures !== [] || $this->lockLostSites !== [];
    }

    public function isEmpty(): bool
    {
        return $this->purged === [] && $this->failures === [] && $this->busySites === [] && $this->lockLostSites === [];
    }
}
