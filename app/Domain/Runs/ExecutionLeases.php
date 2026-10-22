<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use App\Domain\Locks\SiteLease;
use App\Domain\Runs\Concurrency\SlotLease;

/**
 * Everything an execution holds besides its claim: concurrency slots and the
 * site/account lock. The ticker refreshes them together.
 */
final class ExecutionLeases
{
    public ?SlotLease $slots = null;

    public ?SiteLease $site = null;

    public function refresh(): bool
    {
        return ($this->slots === null || $this->slots->refresh())
            && ($this->site === null || $this->site->refresh());
    }

    public function release(): void
    {
        $this->site?->release();
        $this->slots?->release();
    }
}
