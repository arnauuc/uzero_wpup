<?php

declare(strict_types=1);

namespace App\Domain\Runs\Concurrency;

/**
 * Slots held by one execution at every concurrency level. Refreshed by the
 * engine's ticker; a dead worker's slots expire after the lease.
 */
final class SlotLease
{
    private bool $released = false;

    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        private readonly ConcurrencyGate $gate,
        public readonly array $keys,
        public readonly string $token,
    ) {}

    public function refresh(): bool
    {
        return ! $this->released && $this->gate->refresh($this);
    }

    public function release(): void
    {
        if (! $this->released) {
            $this->gate->release($this);
            $this->released = true;
        }
    }
}
