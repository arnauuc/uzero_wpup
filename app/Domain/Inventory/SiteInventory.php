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
 * - pending_updates: list<array{type: 'core'|'plugin'|'theme'|'woocommerce', slug: string, wporg_slug: ?string,
 *   current_version: string, new_version: string, package_host: ?string, requires_php: ?string,
 *   requires_wp: ?string, requires_wc: ?string, source: 'wordpress.org'|'transient', licensed: bool}>
 *   (UZEROWPUP-32, see PendingUpdates; slug is the installed name; package URLs are never stored;
 *   licensed=false is a premium package without download URL)
 * - environment.updates: array{warnings: list<string>}
 * - environment.state (UZEROWPUP-33, see SiteState; a null field could not be read, see warnings):
 *   array{woocommerce: ?array{version: string, db_version: ?string, db_update_needed: ?bool,
 *   overridden_templates: ?list<array{file: string, theme_version: ?string, core_version: ?string, outdated: bool}>},
 *   cron: ?array{disabled: bool, overdue_events: int, oldest_overdue_seconds: ?int},
 *   maintenance: ?bool, disk_free_bytes: ?int, warnings: list<string>}
 *   (woocommerce is null when WooCommerce is not installed and active; null anywhere else = could not be read,
 *   see warnings; maintenance is true for a fresh .maintenance file, like wp_is_maintenance_mode())
 *
 * - environment.prerequisites (UZEROWPUP-34, see SitePrerequisites): list<array{name: 'php_minimum'|'php_verified'|
 *   'backup_space'|'wp_cli'|'writable', passed: bool, reason: ?string, value: int|string|null}>. The snapshot
 *   only records them; SiteBlocking (called by InventoryStep, so a dry run can leave the site alone) blocks the
 *   site with their reasons. The block lives on the sites row (blocked_reason, blocked_at, blocked_by).
 *
 * PHP: the version WP-CLI runs with (UZEROWPUP-29) is cross-checked with the
 * HTTP probe (UZEROWPUP-30). The web wins; if the minor versions differ, the
 * site must not be updated and blocked_reason says why (the php_verified prerequisite acts on it).
 */
final readonly class SiteInventory
{
    /**
     * @param  HttpProbe|null  $probe  null only in tests that do not exercise it
     * @param  PendingUpdates|null  $pending  null only in tests that do not exercise it (pending_updates stays empty)
     * @param  SiteState|null  $state  null only in tests that do not exercise it (environment.state is left out)
     * @param  SitePrerequisites|null  $prerequisites  null only in tests that do not exercise it (environment.prerequisites is left out)
     */
    public function __construct(
        private ?HttpProbe $probe = null,
        private ?PendingUpdates $pending = null,
        private ?SiteState $state = null,
        private ?SitePrerequisites $prerequisites = null,
    ) {}

    public const string THEME_ACTIVE = 'active';

    public const string THEME_PARENT = 'parent';

    public const string THEME_INACTIVE = 'inactive';

    /**
     * $probeWeb false (dry runs) skips the HTTP probe, which writes a temporary
     * file to the site.
     */
    public function take(Site $site, SiteDriver $driver, ?Run $run = null, bool $probeWeb = true): SiteSnapshot
    {
        $installed = $driver->installedComponents();
        $php = $driver->phpRuntime();
        $probe = $probeWeb ? $this->probe?->run($site, $driver) : null;
        [$phpVersion, $phpSource, $phpEnvironment] = $this->php($php, $probe, $probeWeb, $driver);

        $pending = $this->pending?->read($driver, $installed, now()->getTimestamp());

        $state = $this->state?->read($driver, $installed, now()->getTimestamp());

        $prerequisites = $this->prerequisites?->check($driver, $phpVersion, $phpEnvironment, $state->diskFreeBytes ?? null);

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
            'pending_updates' => array_map(static fn (PendingUpdate $u): array => $u->toArray(), $pending->updates ?? []),
            'environment' => [
                'php' => $phpEnvironment,
                'updates' => ['warnings' => $pending->warnings ?? []],
                ...($state === null ? [] : ['state' => $state->toArray()]),
                ...($prerequisites === null ? [] : ['prerequisites' => array_map(static fn (PrerequisiteCheck $c): array => $c->toArray(), $prerequisites)]),
            ],
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
    private function php(PhpRuntime $cli, ?HttpProbeResult $probe, bool $probeWeb, SiteDriver $driver): array
    {
        // The web wins: if WP-CLI guessed another PHP (a stale Plesk pool) and
        // the web's version is installed, WP-CLI switches to it.
        $webMinor = $probe?->minor();
        if ($webMinor !== null && $webMinor !== $cli->minor()) {
            $cli = $driver->adoptWebPhp($webMinor) ?? $cli;
        }

        $warnings = $cli->warnings;
        $version = $cli->version;
        $source = $cli->source;
        $blocked = null;

        if ($probe !== null && $probe->version !== null) {
            $version = $probe->version;
            $source = PhpVersionSource::HttpProbe;
            // The web confirmed it: the CLI PHP is no longer unverified.
            $warnings = array_values(array_filter($warnings, static fn (string $w): bool => $w !== PhpRuntime::UNVERIFIED_WARNING));

            if ($probe->minor() !== $cli->minor()) {
                $blocked = "El web serveix PHP {$probe->version}, però WP-CLI fa servir PHP {$cli->version} ({$cli->binary}): i aquesta versió no està instal·lada per a WP-CLI: no s'actualitza fins que coincideixin.";
                $warnings[] = $blocked;
            }
        } elseif ($probe !== null) {
            $warnings[] = "El probe HTTP no ha pogut confirmar el PHP del web: {$probe->error}.";

            // Nothing tells which PHP the web runs: do not update on a guess.
            if ($cli->source === PhpVersionSource::Cli) {
                $blocked = "No se sap quin PHP serveix el web (només el del compte, {$cli->version}): no s'actualitza fins que es pugui verificar.";
                $warnings[] = $blocked;
            }
        } elseif (! $probeWeb) {
            $warnings[] = 'Probe HTTP no executat (dry-run): el PHP del web no s\'ha verificat.';
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
