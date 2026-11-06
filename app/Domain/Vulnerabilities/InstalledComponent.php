<?php

declare(strict_types=1);

namespace App\Domain\Vulnerabilities;

use App\Enums\ComponentType;

/**
 * One installed component asked to a VulnerabilitySource.
 */
final readonly class InstalledComponent
{
    public function __construct(
        public ComponentType $type,
        /** The installed slug. */
        public string $slug,
        /** The WordPress.org slug, when it is a WordPress.org package. */
        public ?string $wporgSlug,
        public string $version,
    ) {}

    public function id(): string
    {
        return "{$this->type->value}\0{$this->slug}\0{$this->version}";
    }
}
