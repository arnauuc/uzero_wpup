<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\Data\InstalledComponents;
use App\Domain\Drivers\SiteDriver;
use App\Enums\ComponentType;
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
 * pending_updates and environment stay empty until UZEROWPUP-32/33.
 */
final readonly class SiteInventory
{
    public const string THEME_ACTIVE = 'active';

    public const string THEME_PARENT = 'parent';

    public const string THEME_INACTIVE = 'inactive';

    public function take(Site $site, SiteDriver $driver, ?Run $run = null): SiteSnapshot
    {
        $installed = $driver->installedComponents();
        $php = $driver->phpRuntime();

        $values = [
            'site_id' => $site->id,
            'core_version' => $installed->coreVersion,
            // The PHP the domain is served with as far as the driver can tell
            // (UZEROWPUP-29); the HTTP probe (UZEROWPUP-30) cross-checks it.
            'php_version' => $php->version,
            'php_source' => $php->source,
            'db_server_version' => $installed->dbServerVersion,
            'plugins' => $this->plugins($installed),
            'themes' => $this->themes($installed),
            'dropins' => $this->simple($installed, ComponentType::Dropin),
            'mu_plugins' => $this->simple($installed, ComponentType::MuPlugin),
            'pending_updates' => [],
            'environment' => ['php' => ['binary' => $php->binary, 'warnings' => $php->warnings]],
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
