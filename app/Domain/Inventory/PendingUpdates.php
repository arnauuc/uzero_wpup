<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Drivers\Data\CommandResult;
use App\Domain\Drivers\Data\InstalledComponents;
use App\Domain\Drivers\SiteDriver;
use App\Enums\ComponentType;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Pending updates of a site (UZEROWPUP-32): the site's own `update_plugins` and
 * `update_themes` site transients (they carry premium components whose licence
 * is registered on the site) plus `wp core check-update`, enriched with the
 * WordPress.org info API for the components that live on WordPress.org.
 *
 * Why plugins/info + themes/info (one GET per component) and not update-check:
 * update-check needs the full serialized plugin headers of the site and only
 * answers for what is installed; the info endpoints answer by slug, return
 * `requires`/`requires_php` of the latest release and are plain GETs. The
 * number of lookups is capped (config) and only pending updates are looked up.
 *
 * Requirements: `requires_php` and `requires_wp` come from WordPress.org for
 * its components and from the transient (`requires_php`, `requires`) for the
 * rest, else null. `requires_wc` ("WC requires at least" is a plugin header)
 * is not exposed by WordPress.org or by the core transient; it is only filled
 * if a licence server adds `wc_requires` / `wc_requires_at_least` to its
 * transient entry, otherwise null. Licences are not managed: a package without
 * download URL is reported with licensed=false.
 *
 * A failure reading the transients or WordPress.org never fails the inventory:
 * it becomes a warning and the transient data is kept.
 */
final readonly class PendingUpdates
{
    public function __construct(
        private Factory $http,
        private string $pluginsInfoUrl,
        private string $themesInfoUrl,
        private int $httpTimeoutSeconds,
        private int $commandTimeoutSeconds,
        private int $maxLookups,
        private int $staleAfterSeconds,
    ) {}

    public function read(SiteDriver $driver, InstalledComponents $installed, int $now): PendingUpdatesResult
    {
        $warnings = [];
        $updates = [];
        $lookups = 0;

        foreach ($this->coreUpdates($driver, $installed->coreVersion, $warnings) as $update) {
            $updates[] = $update;
        }

        foreach ([ComponentType::Plugin, ComponentType::Theme] as $kind) {
            $transient = $this->transient($driver, $kind, $now, $warnings);

            foreach ($transient as $key => $entry) {
                $slug = $this->slugOf($kind, (string) $key, $entry);
                if ($slug === null || ! is_string($entry['new_version'] ?? null) || $entry['new_version'] === '') {
                    continue;
                }

                $fromOrg = $this->isWordPressOrg($entry);
                $info = [];
                if ($fromOrg && $lookups < $this->maxLookups) {
                    $lookups++;
                    $info = $this->lookup($kind, $slug, $warnings);
                } elseif ($fromOrg) {
                    $warnings[] = "S'ha superat el límit de consultes a WordPress.org: «{$slug}» només té les dades del lloc.";
                }

                [$package, $hasQuery] = $this->package($entry['package'] ?? null);
                $type = $kind === ComponentType::Theme ? 'theme' : ($slug === 'woocommerce' ? 'woocommerce' : 'plugin');

                $updates[] = new PendingUpdate(
                    $type,
                    $slug,
                    $this->currentVersion($installed, $kind, $slug),
                    $entry['new_version'],
                    $package,
                    $hasQuery,
                    $this->text($info['requires_php'] ?? null) ?? $this->text($entry['requires_php'] ?? null),
                    $this->text($info['requires'] ?? null) ?? $this->text($entry['requires'] ?? null),
                    $this->text($entry['wc_requires'] ?? null) ?? $this->text($entry['wc_requires_at_least'] ?? null),
                    $fromOrg ? PendingUpdate::SOURCE_WORDPRESS_ORG : PendingUpdate::SOURCE_TRANSIENT,
                    $package !== null,
                );
            }
        }

        usort($updates, static fn (PendingUpdate $a, PendingUpdate $b): int => [$a->type, $a->slug] <=> [$b->type, $b->slug]);

        return new PendingUpdatesResult($updates, $warnings);
    }

    /**
     * @param  list<string>  $warnings
     * @return list<PendingUpdate>
     */
    private function coreUpdates(SiteDriver $driver, string $current, array &$warnings): array
    {
        try {
            $result = $driver->wp(['core', 'check-update', '--format=json'], $this->commandTimeoutSeconds);
        } catch (Throwable $e) {
            $warnings[] = "No s'han pogut llegir les actualitzacions del core: {$e->getMessage()}";

            return [];
        }

        // "Success: WordPress is at the latest version." is not JSON: no updates.
        $rows = $result->successful() ? json_decode($result->stdout, true) : null;
        $updates = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! is_string($row['version'] ?? null)) {
                continue;
            }
            [$package, $hasQuery] = $this->package($row['package_url'] ?? null);
            $updates[] = new PendingUpdate('core', 'wordpress', $current, $row['version'], $package, $hasQuery, null, null, null, PendingUpdate::SOURCE_WORDPRESS_ORG, $package !== null);
        }

        return $updates;
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string, array<string, mixed>>
     */
    private function transient(SiteDriver $driver, ComponentType $kind, int $now, array &$warnings): array
    {
        $name = $kind === ComponentType::Theme ? 'update_themes' : 'update_plugins';

        try {
            $result = $driver->wp(['transient', 'get', $name, '--network', '--format=json'], $this->commandTimeoutSeconds);
        } catch (Throwable $e) {
            $warnings[] = "No s'ha pogut llegir el transient {$name}: {$e->getMessage()}";

            return [];
        }

        if (! $result->successful()) {
            // WP-CLI exits 1 with no output when the transient was never set.
            if (trim($result->stdout) !== '' || ! str_contains($result->stderr, 'not set')) {
                $warnings[] = "No s'ha pogut llegir el transient {$name}.";
            }

            return [];
        }

        $data = $this->decode($result);
        if ($data === null) {
            $warnings[] = "El transient {$name} no és JSON vàlid.";

            return [];
        }

        $checked = $data['last_checked'] ?? null;
        if (is_int($checked) && $now - $checked > $this->staleAfterSeconds) {
            $warnings[] = "El transient {$name} és antic: el lloc no ha comprovat actualitzacions fa més de ".intdiv($this->staleAfterSeconds, 3600).' h.';
        }

        $response = $data['response'] ?? [];
        $entries = [];
        foreach (is_array($response) ? $response : [] as $key => $entry) {
            if (is_array($entry)) {
                $entries[(string) $key] = $this->stringKeys($entry);
            }
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(CommandResult $result): ?array
    {
        $data = json_decode($result->stdout, true);
        if (! is_array($data)) {
            return null;
        }

        return $this->stringKeys($data);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    private function stringKeys(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function slugOf(ComponentType $kind, string $key, array $entry): ?string
    {
        $slug = $entry['slug'] ?? ($kind === ComponentType::Theme ? ($entry['theme'] ?? $key) : dirname($key));
        // A single-file plugin has no directory: use the file name.
        if ($slug === '.' || $slug === '') {
            $slug = basename($key, '.php');
        }

        return is_string($slug) && preg_match('/^[a-z0-9._-]+$/i', $slug) === 1 ? $slug : null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isWordPressOrg(array $entry): bool
    {
        // Plugins carry `id` = "w.org/plugins/<slug>"; theme entries only the URL.
        return (is_string($entry['id'] ?? null) && str_starts_with($entry['id'], 'w.org/'))
            || (is_string($entry['url'] ?? null) && str_starts_with($entry['url'], 'https://wordpress.org/'));
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function lookup(ComponentType $kind, string $slug, array &$warnings): array
    {
        $theme = $kind === ComponentType::Theme;

        try {
            $response = $this->http->timeout($this->httpTimeoutSeconds)->acceptJson()->get($theme ? $this->themesInfoUrl : $this->pluginsInfoUrl, [
                'action' => $theme ? 'theme_information' : 'plugin_information',
                'request' => ['slug' => $slug, 'fields' => $theme
                    ? ['requires' => 1, 'requires_php' => 1]
                    : ['sections' => 0, 'description' => 0, 'short_description' => 0, 'versions' => 0, 'banners' => 0, 'icons' => 0, 'reviews' => 0]],
            ]);
            $data = $response->successful() ? $response->json() : null;
        } catch (Throwable) {
            $data = null;
        }

        if (! is_array($data) || isset($data['error'])) {
            $warnings[] = "WordPress.org no ha respost per «{$slug}»: es conserven les dades del lloc.";

            return [];
        }

        return $this->stringKeys($data);
    }

    /**
     * @return array{0: ?string, 1: bool} URL without query/fragment, and whether any was removed
     */
    private function package(mixed $url): array
    {
        if (! is_string($url) || trim($url) === '') {
            return [null, false];
        }

        $position = strcspn($url, '?#');

        return [substr($url, 0, $position), $position < strlen($url)];
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function currentVersion(InstalledComponents $installed, ComponentType $kind, string $slug): string
    {
        foreach ($installed->components as $component) {
            if ($component->slug === $slug && in_array($component->type, $kind === ComponentType::Theme ? [ComponentType::Theme] : [ComponentType::Plugin, ComponentType::Woocommerce], true)) {
                return $component->version;
            }
        }

        return '';
    }
}
