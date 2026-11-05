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
     */
    public function __construct(
        public array $purged = [],
        public array $failures = [],
        public array $busySites = [],
    ) {}

    public function failed(): bool
    {
        return $this->failures !== [];
    }

    public function isEmpty(): bool
    {
        return $this->purged === [] && $this->failures === [] && $this->busySites === [];
    }
}
