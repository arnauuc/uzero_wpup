<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Data;

use App\Enums\ComponentType;

/**
 * What is installed on a site, as read by the driver.
 */
final readonly class InstalledComponents
{
    /**
     * @param  list<ComponentVersion>  $components  plugins, themes, drop-ins, must-use plugins
     */
    public function __construct(
        public string $coreVersion,
        public string $phpVersion,
        public string $dbServerVersion,
        public array $components,
        public ?string $activeTheme = null,
        public ?string $parentTheme = null,
    ) {}

    public function find(ComponentType $type, string $slug): ?ComponentVersion
    {
        foreach ($this->components as $component) {
            if ($component->type === $type && $component->slug === $slug) {
                return $component;
            }
        }

        return null;
    }
}
