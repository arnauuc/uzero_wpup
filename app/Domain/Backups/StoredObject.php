<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use Carbon\CarbonImmutable;

/**
 * An object held by a BackupTarget. $sha256 is set only when the target
 * verified the stored bytes as part of the call that returned it.
 */
final readonly class StoredObject
{
    public function __construct(
        public string $key,
        public int $sizeBytes,
        public ?CarbonImmutable $modifiedAt = null,
        public ?string $sha256 = null,
    ) {}
}
