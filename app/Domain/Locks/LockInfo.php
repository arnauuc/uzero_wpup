<?php

declare(strict_types=1);

namespace App\Domain\Locks;

/**
 * One held lock, as listed by `lock:status`.
 */
final readonly class LockInfo
{
    public function __construct(
        public LockLevel $level,
        public int $objectId,
        public LockHolder $holder,
        public int $secondsLeft,
    ) {}
}
