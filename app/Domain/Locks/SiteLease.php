<?php

declare(strict_types=1);

namespace App\Domain\Locks;

/**
 * A held pair of locks (site + hosting account). The run must refresh() it more
 * often than the TTL and release() it when it ends.
 */
final class SiteLease
{
    private bool $released = false;

    public function __construct(
        private readonly SiteLockManager $manager,
        public readonly int $siteId,
        public readonly int $hostingAccountId,
        public readonly string $owner,
    ) {}

    /**
     * Extends the lease. Returns false if it was lost (expired or force-released):
     * the run must then stop and go to recovery.
     */
    public function refresh(): bool
    {
        return ! $this->released && $this->manager->refresh($this);
    }

    public function release(): void
    {
        if (! $this->released) {
            $this->manager->release($this);
            $this->released = true;
        }
    }
}
