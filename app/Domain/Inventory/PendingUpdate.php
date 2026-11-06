<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/**
 * One pending update of a site, as stored in site_snapshots.pending_updates.
 * No package URL is stored: it can carry the licence in userinfo, path or
 * query. Only the host is kept; the download (F3) reads the URL from the site
 * again at update time.
 */
final readonly class PendingUpdate
{
    public const string SOURCE_WORDPRESS_ORG = 'wordpress.org';

    public const string SOURCE_TRANSIENT = 'transient';

    /**
     * @param  list<string>|null  $requiresPlugins
     */
    public function __construct(
        public string $type,
        /** The installed name, as `wp plugin list` / `wp theme list` report it. */
        public string $slug,
        /** The slug on WordPress.org when it differs from the installed name; null if unknown. */
        public ?string $wporgSlug,
        public string $currentVersion,
        public string $newVersion,
        public ?string $packageHost,
        public ?string $requiresPhp,
        public ?string $requiresWp,
        public ?string $requiresWc,
        public string $source,
        /** False for a premium package without download URL («sense llicència»). */
        public bool $licensed,
        /** Slugs in the "Requires Plugins" of the new version (WP 6.5+); null if unknown. */
        public ?array $requiresPlugins = null,
        /** True when $requiresPlugins comes from the installed version, not the new one. */
        public bool $requiresPluginsInferred = false,
    ) {}

    /**
     * The inverse of toArray(); null when the stored row is not usable.
     *
     * @param  array<mixed>  $row
     */
    public static function fromArray(array $row): ?self
    {
        $plugins = $row['requires_plugins'] ?? null;
        $optional = static fn (string $key): ?string => is_string($row[$key] ?? null) ? $row[$key] : null;
        if (! is_string($row['type'] ?? null) || ! is_string($row['slug'] ?? null) || ! is_string($row['current_version'] ?? null) || ! is_string($row['new_version'] ?? null)) {
            return null;
        }

        return new self(
            $row['type'],
            $row['slug'],
            $optional('wporg_slug'),
            $row['current_version'],
            $row['new_version'],
            $optional('package_host'),
            $optional('requires_php'),
            $optional('requires_wp'),
            $optional('requires_wc'),
            $optional('source') ?? self::SOURCE_TRANSIENT,
            ($row['licensed'] ?? true) !== false,
            is_array($plugins) ? array_values(array_filter($plugins, is_string(...))) : null,
            ($row['requires_plugins_inferred'] ?? false) === true,
        );
    }

    /**
     * @return array{type: string, slug: string, wporg_slug: ?string, current_version: string, new_version: string, package_host: ?string, requires_php: ?string, requires_wp: ?string, requires_wc: ?string, source: string, licensed: bool, requires_plugins: list<string>|null, requires_plugins_inferred: bool}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'slug' => $this->slug,
            'wporg_slug' => $this->wporgSlug,
            'current_version' => $this->currentVersion,
            'new_version' => $this->newVersion,
            'package_host' => $this->packageHost,
            'requires_php' => $this->requiresPhp,
            'requires_wp' => $this->requiresWp,
            'requires_wc' => $this->requiresWc,
            'source' => $this->source,
            'licensed' => $this->licensed,
            'requires_plugins' => $this->requiresPlugins,
            'requires_plugins_inferred' => $this->requiresPluginsInferred,
        ];
    }
}
