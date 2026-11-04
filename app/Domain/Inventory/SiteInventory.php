<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\Data\InstalledComponents;
use App\Domain\Drivers\Data\PhpRuntime;
use App\Domain\Drivers\SiteDriver;
use App\Enums\ComponentType;
use App\Enums\PhpVersionSource;
use App\Models\Run;
use App\Models\Site;
use App\Models\SiteSnapshot;

/**
 * Takes a fresh inventory of a site and stores it as a new site_snapshots row.
 * It always reads the site through the driver and never reuses an earlier
 * snapshot (AGENTS.md: fresh data on every run).
 *
 * JSON shapes stored in the snapshot (lists ordered by slug):
 * - plugins:    list<array{slug: string, version: string, active: bool, title: ?string, type: 'plugin'|'woocommerce'}>
 * - themes:     list<array{slug: string, version: string, status: 'active'|'parent'|'inactive', title: ?string}>
 * - dropins:    list<array{slug: string, version: string, title: ?string}>
 * - mu_plugins: list<array{slug: string, version: string, title: ?string}>
 * - environment.php: array{binary: string, cli_version: string, cli_source: string,
 *   warnings: list<string>, sapi: ?string, extensions: list<string>, blocked_reason: ?string}
 * pending_updates stays empty until UZEROWPUP-32.
 *
 * PHP: the version WP-CLI runs with (UZEROWPUP-29) is cross-checked with the
 * HTTP probe (UZEROWPUP-30). The web wins; if the minor versions differ, the
 * site must not be updated and blocked_reason says why (UZEROWPUP-34 acts on it).
 */
final readonly class SiteInventory
{
    /**
     * @param  HttpProbe|null  $probe  null only in tests that do not exercise it
     */
    public function __construct(private ?HttpProbe $probe = null) {}

    public const string THEME_ACTIVE = 'active';

    public const string THEME_PARENT = 'parent';

    public const string THEME_INACTIVE = 'inactive';

    public function take(Site $site, SiteDriver $driver, ?Run $run = null): SiteSnapshot
    {
        $installed = $driver->installedComponents();
        $php = $driver->phpRuntime();
        [$phpVersion, $phpSource, $phpEnvironment] = $this->php($php, $this->probe?->run($site, $driver));

        $values = [
            'site_id' => $site->id,
            'core_version' => $installed->coreVersion,
            // The PHP the domain is served with as far as the driver can tell
            // (UZEROWPUP-29); the HTTP probe (UZEROWPUP-30) cross-checks it.
            'php_version' => $phpVersion,
            'php_source' => $phpSource,
            'db_server_version' => $installed->dbServerVersion,
            'plugins' => $this->plugins($installed),
            'themes' => $this->themes($installed),
            'dropins' => $this->simple($installed, ComponentType::Dropin),
            'mu_plugins' => $this->simple($installed, ComponentType::MuPlugin),
            'pending_updates' => [],
            'environment' => ['php' => $phpEnvironment],
            'taken_at' => now(),
        ];

        // One snapshot per run: a retried step (e.g. after a worker crash)
        // replaces it instead of adding another.
        $snapshot = $run === null
            ? SiteSnapshot::query()->create($values)
            : SiteSnapshot::query()->updateOrCreate(['run_id' => $run->id], $values);

        // Reload so the columns left to their database defaults are filled.
        return $snapshot->refresh();
    }

    /**
     * @return array{0: string, 1: PhpVersionSource, 2: array<string, mixed>}
     */
    private function php(PhpRuntime $cli, ?HttpProbeResult $probe): array
    {
        $warnings = $cli->warnings;
        $version = $cli->version;
        $source = $cli->source;
        $blocked = null;

        if ($probe !== null && $probe->version !== null) {
            $version = $probe->version;
            $source = PhpVersionSource::HttpProbe;

            if ($probe->minor() !== $cli->minor()) {
                $blocked = "El web serveix PHP {$probe->version}, però WP-CLI fa servir PHP {$cli->version} ({$cli->binary}): no s'actualitza fins que coincideixin.";
                $warnings[] = $blocked;
            }
        } elseif ($probe !== null) {
            $warnings[] = "El probe HTTP no ha pogut confirmar el PHP del web: {$probe->error}.";
        }

        if ($probe !== null && ! $probe->removed) {
            $warnings[] = "No s'ha pogut esborrar el probe {$probe->fileName} del lloc; caduca sol i no respon a ningú més.";
        }

        return [$version, $source, [
            'binary' => $cli->binary,
            'cli_version' => $cli->version,
            'cli_source' => $cli->source->value,
            'warnings' => $warnings,
            'sapi' => $probe?->sapi,
            'extensions' => $probe->extensions ?? [],
            'blocked_reason' => $blocked,
        ]];
    }

    /**
     * @return list<array{slug: string, version: string, active: bool, title: string|null, type: string}>
     */
    private function plugins(InstalledComponents $installed): array
    {
        $rows = [];
        foreach ($this->ofTypes($installed, [ComponentType::Plugin, ComponentType::Woocommerce]) as $component) {
            $rows[] = [
                'slug' => $component->slug,
                'version' => $component->version,
                'active' => $component->active,
                'title' => $component->title,
                'type' => $component->type->value,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{slug: string, version: string, status: string, title: string|null}>
     */
    private function themes(InstalledComponents $installed): array
    {
        $rows = [];
        foreach ($this->ofTypes($installed, [ComponentType::Theme]) as $component) {
            $rows[] = [
                'slug' => $component->slug,
                'version' => $component->version,
                'status' => match (true) {
                    $component->slug === $installed->activeTheme => self::THEME_ACTIVE,
                    $component->slug === $installed->parentTheme => self::THEME_PARENT,
                    default => self::THEME_INACTIVE,
                },
                'title' => $component->title,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{slug: string, version: string, title: string|null}>
     */
    private function simple(InstalledComponents $installed, ComponentType $type): array
    {
        $rows = [];
        foreach ($this->ofTypes($installed, [$type]) as $component) {
            $rows[] = ['slug' => $component->slug, 'version' => $component->version, 'title' => $component->title];
        }

        return $rows;
    }

    /**
     * @param  list<ComponentType>  $types
     * @return list<ComponentVersion>
     */
    private function ofTypes(InstalledComponents $installed, array $types): array
    {
        $matching = array_values(array_filter(
            $installed->components,
            static fn (ComponentVersion $c): bool => in_array($c->type, $types, true),
        ));
        usort($matching, static fn (ComponentVersion $a, ComponentVersion $b): int => $a->slug <=> $b->slug);

        return $matching;
    }
}
