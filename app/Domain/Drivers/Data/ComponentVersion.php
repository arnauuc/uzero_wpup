<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Data;

use App\Enums\ComponentType;

final readonly class ComponentVersion
{
    public function __construct(
        public ComponentType $type,
        public string $slug,
        public string $version,
        public bool $active = true,
        public ?string $title = null,
    ) {}
}
