<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Data;

/**
 * A file on the site created or uploaded by the tool, with its integrity data.
 */
final readonly class RemoteFile
{
    public function __construct(
        public string $path,
        public int $sizeBytes,
        public string $sha256,
    ) {}
}
