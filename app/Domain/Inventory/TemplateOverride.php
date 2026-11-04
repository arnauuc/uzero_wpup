<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/**
 * A theme template that overrides one of WooCommerce's (UZEROWPUP-33). Updating
 * WooCommerce can break the storefront when the theme's copy is outdated.
 */
final readonly class TemplateOverride
{
    public function __construct(
        /** Path relative to wp-content/themes. */
        public string $file,
        public ?string $themeVersion,
        public ?string $coreVersion,
        /** WooCommerce's copy is newer than the theme's (or the theme's has no @version). */
        public bool $outdated,
    ) {}

    public static function fromVersions(string $file, ?string $themeVersion, ?string $coreVersion): self
    {
        return new self(
            $file,
            $themeVersion,
            $coreVersion,
            $coreVersion !== null && ($themeVersion === null || version_compare($themeVersion, $coreVersion, '<')),
        );
    }

    /**
     * @return array{file: string, theme_version: ?string, core_version: ?string, outdated: bool}
     */
    public function toArray(): array
    {
        return ['file' => $this->file, 'theme_version' => $this->themeVersion, 'core_version' => $this->coreVersion, 'outdated' => $this->outdated];
    }
}
