<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/**
 * One pending update of a site, as stored in site_snapshots.pending_updates.
 * The package URL is kept WITHOUT its query string (licence keys and tokens
 * travel there); packageHasQuery tells that something was removed.
 */
final readonly class PendingUpdate
{
    public const string SOURCE_WORDPRESS_ORG = 'wordpress.org';

    public const string SOURCE_TRANSIENT = 'transient';

    public function __construct(
        public string $type,
        public string $slug,
        public string $currentVersion,
        public string $newVersion,
        public ?string $packageUrl,
        public bool $packageHasQuery,
        public ?string $requiresPhp,
        public ?string $requiresWp,
        public ?string $requiresWc,
        public string $source,
        /** False for a premium package without download URL («sense llicència»). */
        public bool $licensed,
    ) {}

    /**
     * @return array{type: string, slug: string, current_version: string, new_version: string, package_url: ?string, package_has_query: bool, requires_php: ?string, requires_wp: ?string, requires_wc: ?string, source: string, licensed: bool}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'slug' => $this->slug,
            'current_version' => $this->currentVersion,
            'new_version' => $this->newVersion,
            'package_url' => $this->packageUrl,
            'package_has_query' => $this->packageHasQuery,
            'requires_php' => $this->requiresPhp,
            'requires_wp' => $this->requiresWp,
            'requires_wc' => $this->requiresWc,
            'source' => $this->source,
            'licensed' => $this->licensed,
        ];
    }
}
