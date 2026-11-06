<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Data;

use App\Enums\ComponentType;

/**
 * A package to install, as the tool server holds it: the artifact analysed and
 * verified there (UZEROWPUP-49), with its size and SHA-256. The driver uploads
 * these exact bytes, so staging and production get the same ones and the site
 * never downloads a package itself.
 */
final readonly class UpdatePackage
{
    public function __construct(
        public ComponentType $type,
        public string $slug,
        public string $targetVersion,
        /** Absolute path on the tool server. */
        public string $localPath,
        public string $sha256,
        public int $sizeBytes,
    ) {}
}
