<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/**
 * WooCommerce state of a site (UZEROWPUP-33). A null field means it could not
 * be read (there is a warning), never "all fine".
 */
final readonly class WooCommerceState
{
    /**
     * @param  list<TemplateOverride>|null  $overriddenTemplates
     */
    public function __construct(
        public string $version,
        public ?string $dbVersion,
        public ?bool $dbUpdateNeeded,
        public ?array $overriddenTemplates,
    ) {}

    /**
     * @return array{version: string, db_version: ?string, db_update_needed: ?bool, overridden_templates: list<array{file: string, theme_version: ?string, core_version: ?string, outdated: bool}>|null}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'db_version' => $this->dbVersion,
            'db_update_needed' => $this->dbUpdateNeeded,
            'overridden_templates' => $this->overriddenTemplates === null
                ? null
                : array_map(static fn (TemplateOverride $t): array => $t->toArray(), $this->overriddenTemplates),
        ];
    }
}
