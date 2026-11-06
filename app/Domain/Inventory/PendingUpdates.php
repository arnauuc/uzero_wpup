<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Drivers\Data\CommandResult;
use App\Domain\Drivers\Data\InstalledComponents;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\ComponentType;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

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
 * Installed names: `slug` is the name `wp plugin list` uses (plugin folder, or
 * the file name without .php for a single-file plugin; the theme key);
 * `wporg_slug` is the WordPress.org slug and is only used for the API query.
 * Transient entries of components that are not installed are dropped with a
 * warning.
 *
 * Requirements: `requires_php` and `requires_wp` come from the transient
 * (`requires_php`, `requires`) when present; WordPress.org is asked (pooled,
 * cached per slug and version) only for the ones that are missing, else null. `requires_wc` ("WC requires at least" is a plugin header)
 * is not exposed by WordPress.org or by the core transient; it is only filled
 * if a licence server adds `wc_requires` / `wc_requires_at_least` to its
 * transient entry, otherwise null. `requires_plugins` (the "Requires Plugins"
 * header, WP 6.5+) comes from the transient entry, else from the WordPress.org
 * answer, else from the header of the installed version (one `wp eval` for all
 * plugins; a plugin rarely drops a dependency in an update), else null.
 * Licences are not managed: a package without
 * download URL is reported with licensed=false.
 *
 * A command failing on the site, or WordPress.org failing, never fails the
 * inventory: it becomes a warning and the transient data is kept. A lost
 * connection does propagate. Text from the site goes through the redactor.
 */
final readonly class PendingUpdates
{
    /** Installed plugins' "Requires Plugins" header, by plugin file. Constant: never built from input. */
    public const string REQUIRES_PLUGINS_SNIPPET = <<<'PHP'
if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
$out = array();
foreach (get_plugins() as $file => $data) {
    if (!empty($data['RequiresPlugins'])) { $out[$file] = (string) $data['RequiresPlugins']; }
}
echo "\n" . json_encode((object) $out, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
PHP;

    public function __construct(
        private Factory $http,
        private Cache $cache,
        private SecretRedactor $redactor,
        private WpCliReader $reader,
        private string $pluginsInfoUrl,
        private string $themesInfoUrl,
        private int $httpTimeoutSeconds,
        private int $commandTimeoutSeconds,
        private int $maxLookups,
        private int $staleAfterSeconds,
        private int $cacheTtlSeconds,
    ) {}

    public function read(SiteDriver $driver, InstalledComponents $installed, int $now): PendingUpdatesResult
    {
        $warnings = [];
        $updates = $this->coreUpdates($driver, $installed->coreVersion, $warnings);
        $pending = [];

        foreach ([ComponentType::Plugin, ComponentType::Theme] as $kind) {
            foreach ($this->transient($driver, $kind, $now, $warnings) as $key => $entry) {
                $slug = $this->slugOf($kind, $key);
                $new = $entry['new_version'] ?? null;
                if ($slug === null || ! is_string($new) || $new === '') {
                    continue;
                }

                $current = $this->currentVersion($installed, $kind, $slug);
                if ($current === null) {
                    $warnings[] = "L'actualització de «{$slug}» surt al transient però el component no està instal·lat: descartada.";

                    continue;
                }

                $fromOrg = $this->isWordPressOrg($entry);
                $wporgSlug = $this->reader->text($entry['slug'] ?? null) ?? $this->reader->text($entry['theme'] ?? null);
                $pending[] = [
                    'kind' => $kind, 'slug' => $slug, 'wporg' => $fromOrg ? ($wporgSlug ?? $slug) : $wporgSlug,
                    'from_org' => $fromOrg, 'current' => $current, 'new' => $new, 'entry' => $entry,
                    'php' => $this->reader->text($entry['requires_php'] ?? null), 'wp' => $this->reader->text($entry['requires'] ?? null),
                    'plugins' => $kind === ComponentType::Plugin ? self::slugList($entry['requires_plugins'] ?? null, $slug) : [],
                ];
            }
        }

        $info = $this->lookups($pending, $warnings);
        $requiresPlugins = [];
        foreach ($pending as $i => $row) {
            $found = $info[$this->cacheKey($row['kind'], (string) $row['wporg'], $row['new'])] ?? [];
            $requiresPlugins[$i] = $row['plugins'] ?? self::slugList($found['requires_plugins'] ?? null, $row['slug']);
        }
        $headers = in_array(null, $requiresPlugins, true) ? $this->installedRequiresPlugins($driver, $warnings) : null;
        foreach ($headers === null ? [] : $requiresPlugins as $i => $slugs) {
            $requiresPlugins[$i] = $slugs ?? self::slugList($headers[$pending[$i]['slug']] ?? '', $pending[$i]['slug']);
        }

        foreach ($pending as $i => $row) {
            $kind = $row['kind'];
            $found = $info[$this->cacheKey($kind, (string) $row['wporg'], $row['new'])] ?? [];
            [$host, $licensed] = $this->package($row['entry']['package'] ?? null);
            $type = $kind === ComponentType::Theme ? 'theme' : ($row['slug'] === 'woocommerce' ? 'woocommerce' : 'plugin');

            $updates[] = new PendingUpdate(
                $type,
                $row['slug'],
                $row['wporg'],
                $row['current'],
                $row['new'],
                $host,
                $row['php'] ?? $this->reader->text($found['requires_php'] ?? null),
                $row['wp'] ?? $this->reader->text($found['requires'] ?? null),
                $this->reader->text($row['entry']['wc_requires'] ?? null) ?? $this->reader->text($row['entry']['wc_requires_at_least'] ?? null),
                $row['from_org'] ? PendingUpdate::SOURCE_WORDPRESS_ORG : PendingUpdate::SOURCE_TRANSIENT,
                $licensed,
                $requiresPlugins[$i],
            );
        }

        usort($updates, static fn (PendingUpdate $a, PendingUpdate $b): int => [$a->type, $a->slug, $a->newVersion] <=> [$b->type, $b->slug, $b->newVersion]);

        return new PendingUpdatesResult($updates, $warnings);
    }

    /**
     * @param  list<string>  $warnings
     * @return list<PendingUpdate>
     */
    private function coreUpdates(SiteDriver $driver, string $current, array &$warnings): array
    {
        $result = $this->run($driver, ['core', 'check-update', '--format=json'], 'les actualitzacions del core', $warnings);
        if ($result === null) {
            return [];
        }

        // With --format=json "no updates" is `[]`.
        $rows = json_decode($result->stdout, true);
        if (! $result->successful() || ! is_array($rows)) {
            $warnings[] = "No s'han pogut llegir les actualitzacions del core.";

            return [];
        }

        $updates = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['version'] ?? null)) {
                continue;
            }
            [$host, $licensed] = $this->package($row['package_url'] ?? null);
            $updates[] = new PendingUpdate('core', 'wordpress', null, $current, $row['version'], $host, null, null, null, PendingUpdate::SOURCE_WORDPRESS_ORG, $licensed, []);
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
        $result = $this->run($driver, ['transient', 'get', $name, '--network', '--format=json'], "el transient {$name}", $warnings);
        if ($result === null) {
            return [];
        }

        if (! $result->successful()) {
            $warnings[] = "No s'ha pogut llegir el transient {$name}.";

            return [];
        }

        // WP-CLI exits 0 with empty output (and a warning on stderr) when it is not set.
        if (trim($result->stdout) === '') {
            return [];
        }

        $data = json_decode($result->stdout, true);
        if (! is_array($data)) {
            $warnings[] = "El transient {$name} no és JSON vàlid.";

            return [];
        }

        $checked = $data['last_checked'] ?? null;
        if (is_int($checked) && $now - $checked > $this->staleAfterSeconds) {
            $warnings[] = "El transient {$name} és antic: el lloc no ha comprovat actualitzacions fa més de ".intdiv($this->staleAfterSeconds, 3600).' h.';
        }

        $entries = [];
        $response = $data['response'] ?? [];
        foreach (is_array($response) ? $response : [] as $key => $entry) {
            if (is_array($entry)) {
                $entries[(string) $key] = $this->stringKeys($entry);
            }
        }

        return $entries;
    }

    /**
     * @param  list<string>  $args
     * @param  list<string>  $warnings
     */
    private function run(SiteDriver $driver, array $args, string $what, array &$warnings): ?CommandResult
    {
        return $this->reader->run($driver, $args, $this->commandTimeoutSeconds, $what, $warnings);
    }

    /**
     * Requirements from WordPress.org for the entries that lack them in the
     * transient; cached per slug and version, one pooled request round.
     *
     * @param  list<array{kind: ComponentType, slug: string, wporg: ?string, from_org: bool, current: string, new: string, entry: array<string, mixed>, php: ?string, wp: ?string, plugins: ?list<string>}>  $pending
     * @param  list<string>  $warnings
     * @return array<string, array<string, mixed>> by cache key
     */
    private function lookups(array $pending, array &$warnings): array
    {
        $found = [];
        $toFetch = [];

        foreach ($pending as $row) {
            if (! $row['from_org'] || $row['wporg'] === null || ($row['php'] !== null && $row['wp'] !== null)) {
                continue;
            }
            $key = $this->cacheKey($row['kind'], $row['wporg'], $row['new']);
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                $found[$key] = $this->stringKeys($cached);
            } elseif (count($toFetch) >= $this->maxLookups) {
                $warnings[] = "S'ha superat el límit de consultes a WordPress.org: «{$row['slug']}» només té les dades del lloc.";
            } else {
                $toFetch[$key] = [$row['kind'], $row['wporg']];
            }
        }

        if ($toFetch === []) {
            return $found;
        }

        try {
            $responses = $this->http->pool(function (Pool $pool) use ($toFetch): array {
                $requests = [];
                foreach ($toFetch as $key => [$kind, $slug]) {
                    $requests[] = $this->request($pool->as($key), $kind, $slug);
                }

                return $requests;
            });
        } catch (ConnectionException $e) {
            $this->logFailure('pool', $e);
            $warnings[] = 'WordPress.org no ha respost: es conserven les dades del lloc.';

            return $found;
        }

        foreach ($toFetch as $key => [, $slug]) {
            $response = $responses[$key] ?? null;
            $data = $response instanceof Response && $response->successful() ? $response->json() : null;
            if (! is_array($data) || isset($data['error'])) {
                $this->logFailure($slug, $response instanceof Response ? new \RuntimeException('HTTP '.$response->status()) : $response);
                $warnings[] = "WordPress.org no ha respost per «{$slug}»: es conserven les dades del lloc.";

                continue;
            }
            $data = $this->stringKeys($data);
            $found[$key] = $data;
            $this->cache->put($key, ['requires' => $data['requires'] ?? null, 'requires_php' => $data['requires_php'] ?? null, 'requires_plugins' => $data['requires_plugins'] ?? null], $this->cacheTtlSeconds);
        }

        return $found;
    }

    /**
     * The "Requires Plugins" header of each installed plugin, by slug; only read
     * when an update lacks it. Null (with a warning) if it cannot be read.
     *
     * @param  list<string>  $warnings
     * @return array<string, mixed>|null
     */
    private function installedRequiresPlugins(SiteDriver $driver, array &$warnings): ?array
    {
        $what = 'els plugins que requereixen els plugins instal·lats';
        $result = $this->run($driver, ['eval', self::REQUIRES_PLUGINS_SNIPPET], $what, $warnings);
        $data = $result !== null && $result->successful() ? $this->reader->json($result->stdout) : null;
        if ($data === null) {
            if ($result !== null) {
                $warnings[] = "No s'han pogut llegir {$what}: no es comproven.";
            }

            return null;
        }

        $headers = [];
        foreach ($data as $file => $header) {
            $slug = $this->slugOf(ComponentType::Plugin, (string) $file);
            if ($slug !== null) {
                $headers[$slug] = $header;
            }
        }

        return $headers;
    }

    /**
     * Slugs of a "Requires Plugins" value, cleaned as WordPress does (comma-separated
     * header or WordPress.org list; invalid slugs and the plugin itself dropped),
     * sorted. Null when there is no value at all (unknown, not "none").
     *
     * @return list<string>|null
     */
    private static function slugList(mixed $value, string $self): ?array
    {
        $items = match (true) {
            is_string($value) => explode(',', $value),
            is_array($value) => array_filter($value, is_string(...)),
            default => null,
        };
        if ($items === null) {
            return null;
        }

        $slugs = [];
        foreach ($items as $item) {
            $slug = strtolower(trim($item));
            if ($slug !== $self && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) === 1) {
                $slugs[$slug] = true;
            }
        }
        $slugs = array_keys($slugs);
        sort($slugs);

        return $slugs;
    }

    private function request(PendingRequest $request, ComponentType $kind, string $slug): mixed
    {
        $theme = $kind === ComponentType::Theme;

        return $request->timeout($this->httpTimeoutSeconds)->acceptJson()->get($theme ? $this->themesInfoUrl : $this->pluginsInfoUrl, [
            'action' => $theme ? 'theme_information' : 'plugin_information',
            'request' => ['slug' => $slug, 'fields' => $theme
                ? ['requires' => 1, 'requires_php' => 1]
                : ['sections' => 0, 'description' => 0, 'short_description' => 0, 'versions' => 0, 'banners' => 0, 'icons' => 0, 'reviews' => 0]],
        ]);
    }

    private function logFailure(string $slug, mixed $failure): void
    {
        $detail = $failure instanceof \Throwable ? $failure::class.': '.$failure->getMessage() : 'resposta no vàlida';
        Log::warning($this->redactor->redact("WordPress.org lookup failed for {$slug}: {$detail}"));
    }

    private function cacheKey(ComponentType $kind, string $slug, string $version): string
    {
        return "wpup:wporg:{$kind->value}:{$slug}:{$version}";
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

    private function slugOf(ComponentType $kind, string $key): ?string
    {
        if ($kind === ComponentType::Plugin) {
            $dir = dirname($key);
            $key = $dir === '.' ? basename($key, '.php') : $dir;
        }

        return preg_match('/^[a-z0-9._-]+$/i', $key) === 1 ? $key : null;
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
     * @return array{0: ?string, 1: bool} host (never userinfo, path or query) and whether there is a package
     */
    private function package(mixed $url): array
    {
        if (! is_string($url) || trim($url) === '') {
            return [null, false];
        }
        $host = parse_url(trim($url), PHP_URL_HOST);

        return [is_string($host) && $host !== '' ? strtolower($host) : null, true];
    }

    private function currentVersion(InstalledComponents $installed, ComponentType $kind, string $slug): ?string
    {
        foreach ($installed->components as $component) {
            if ($component->slug === $slug && in_array($component->type, $kind === ComponentType::Theme ? [ComponentType::Theme] : [ComponentType::Plugin, ComponentType::Woocommerce], true)) {
                return $component->version;
            }
        }

        return null;
    }
}
