<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use Carbon\CarbonImmutable;

/**
 * An object held by a BackupTarget.
 */
final readonly class StoredObject
{
    public function __construct(
        public string $key,
        public int $sizeBytes,
        public ?CarbonImmutable $modifiedAt = null,
    ) {}
}
