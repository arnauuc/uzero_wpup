<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Models\SiteSnapshot;

/**
 * What an inventory produced: the stored snapshot and the prerequisite checks
 * as objects (so nobody re-reads them from JSON).
 */
final readonly class InventoryResult
{
    /**
     * @param  list<PrerequisiteCheck>|null  $prerequisites  null only when no SitePrerequisites was injected
     */
    public function __construct(public SiteSnapshot $snapshot, public ?array $prerequisites) {}
}
