<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * A file of a restore point that could not be deleted at one destination: its
 * row stays live and the next purge tries again. $reason is redacted.
 */
final readonly class PruneFailure
{
    public function __construct(
        public int $siteId,
        public string $directory,
        public string $key,
        public string $destination,
        public string $reason,
    ) {}
}
