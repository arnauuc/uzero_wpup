<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use App\Domain\Locks\AccountLease;
use App\Domain\Locks\SiteLease;
use App\Domain\Runs\Concurrency\SlotLease;

/**
 * Everything an execution holds besides its claim: concurrency slots, the
 * site/account lock or the account-only lock. The ticker refreshes them together.
 */
final class ExecutionLeases
{
    public ?SlotLease $slots = null;

    public ?SiteLease $site = null;

    public ?AccountLease $account = null;

    public function refresh(): bool
    {
        return ($this->slots === null || $this->slots->refresh())
            && ($this->site === null || $this->site->refresh())
            && ($this->account === null || $this->account->refresh());
    }

    public function release(): void
    {
        $this->account?->release();
        $this->site?->release();
        $this->slots?->release();
    }
}
