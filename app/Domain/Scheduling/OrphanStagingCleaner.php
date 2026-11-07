<?php

declare(strict_types=1);

namespace App\Domain\Scheduling;

/**
 * Removes the stagings left behind by a site that did not finish (a crash):
 * the scheduler calls it before starting each site (ADR-0006 §2). The
 * ephemeral staging arrives in F7 (UZEROWPUP-100); until then
 * NoOrphanStagingCleaner has nothing to clean.
 */
interface OrphanStagingCleaner
{
    /**
     * Cleans every orphaned staging and says what it removed (operator-facing).
     *
     * @return list<string>
     */
    public function clean(): array;
}
