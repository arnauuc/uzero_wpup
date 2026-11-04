<?php

declare(strict_types=1);

namespace App\Domain\Locks;

/**
 * A held hosting account lock without a site (key rotation, UZEROWPUP-138).
 * Site locks also take their account, so an update and a key rotation on the
 * same account never overlap. Refresh more often than the TTL; release at the end.
 */
final class AccountLease
{
    private bool $released = false;

    public function __construct(
        private readonly SiteLockManager $manager,
        public readonly int $hostingAccountId,
        public readonly string $owner,
    ) {}

    public function refresh(): bool
    {
        return ! $this->released && $this->manager->refreshAccount($this);
    }

    public function release(): void
    {
        if (! $this->released) {
            $this->manager->releaseAccount($this);
            $this->released = true;
        }
    }
}
