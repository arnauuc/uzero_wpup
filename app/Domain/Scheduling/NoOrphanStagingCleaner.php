<?php

declare(strict_types=1);

namespace App\Domain\Scheduling;

/**
 * Until F7 (UZEROWPUP-100) there are no stagings, so none can be orphaned.
 */
final readonly class NoOrphanStagingCleaner implements OrphanStagingCleaner
{
    public function clean(): array
    {
        return [];
    }
}
