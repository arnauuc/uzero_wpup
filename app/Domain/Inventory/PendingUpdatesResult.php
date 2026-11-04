<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

final readonly class PendingUpdatesResult
{
    /**
     * @param  list<PendingUpdate>  $updates
     * @param  list<string>  $warnings  operator-facing, never contain package URLs
     */
    public function __construct(public array $updates, public array $warnings) {}
}
