<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Data;

use App\Enums\ComponentType;

/**
 * A package already uploaded to the site by the tool, with the checksum of the
 * artifact analysed on the tool server (staging and production get the same bytes).
 */
final readonly class UpdatePackage
{
    public function __construct(
        public ComponentType $type,
        public string $slug,
        public string $targetVersion,
        public RemoteFile $file,
    ) {}
}
