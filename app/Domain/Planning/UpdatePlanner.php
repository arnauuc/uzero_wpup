<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Inventory\PrerequisiteCheck;
use App\Domain\Sites\SiteBlocking;
use App\Enums\ComponentType;
use App\Enums\PhpVersionSource;
use App\Enums\PrerequisiteName;
use App\Enums\SkipReason;
use App\Models\Site;
use App\Models\SiteProfile;
use App\Models\SiteSnapshot;

/**
 * Decides, from a fresh snapshot, the site's block and its profile, which pending
 * updates would be applied and which skipped. It reads no site and writes nothing
 * (UZEROWPUP-36). For now it applies the declared requirements and the profile;
 * static analysis (F3), quarantine and --allow-major (UZEROWPUP-127) come later.
 *
 * Profile components (frozen_components, excluded_components) are written as
 * `slug` or `type:slug` (e.g. `akismet`, `plugin:akismet`, `theme:storefront`);
 * the core is `core:wordpress` or `wordpress`. WooCommerce is also a plugin:
 * `woocommerce`, `plugin:woocommerce` and `woocommerce:woocommerce` all match it.
 *
 * The update order is fixed (AGENTS.md): core, WooCommerce, plugins, themes, translations.
 *
 * Rules, the first that matches wins: site blocked (now, or because a prerequisite
 * of the fresh snapshot that does not depend on the HTTP probe fails: the real run
 * would block it), excluded, frozen, unlicensed,
 * PHP required by the update above the site's, WordPress required above the
 * core the site will have, WooCommerce required above the one the site will have,
 * a "Requires Plugins" entry not installed and active, major version (ADR-0005,
 * before F7: needs staging).
 *
 * Dependencies over the whole plan (UZEROWPUP-48): versioned requirements only
 * point at the core and WooCommerce, which are decided first, so one pass in the
 * fixed order is already the fixed point. A requirement that only a skipped core
 * or WooCommerce update would meet is skipped naming that update and its reason.
 * "Requires Plugins" has no versions: it is met by the installed, active plugin
 * whatever happens to that plugin's own update (updates never remove plugins).
 * Versions are compared with WpVersion; a requirement or a site version that
 * cannot be read is never taken as met.
 */
final readonly class UpdatePlanner
{
    /** Update order; a safety rule, not configuration. */
    private const array ORDER = [ComponentType::Core, ComponentType::Woocommerce, ComponentType::Plugin, ComponentType::Theme, ComponentType::Translation];

    /** Note on an update whose "Requires Plugins" comes from the installed version. */
    public const string INFERRED_PLUGINS = "Dependències deduïdes de la versió instal·lada: no s'han pogut confirmar les de la versió nova.";

    /** Prefix of the block a real run would apply, as opposed to one the site already has. */
    public const string WOULD_BLOCK_PREFIX = 'es bloquejaria: ';

    public function plan(SiteSnapshot $snapshot, Site $site, ?SiteProfile $profile, string $phpVersion, PhpVersionSource $phpSource): UpdatePlan
    {
        $blocked = $site->blocked_reason ?? $this->wouldBlock($snapshot);
        $frozen = $profile === null ? [] : self::strings($profile->frozen_components);
        $excluded = $profile === null ? [] : self::strings($profile->excluded_components);

        [$candidates, $otherCoreOffers] = $this->candidates($snapshot);

        // Only an active WooCommerce can become a target: updating an inactive one
        // does not make it meet anyone's requirement.
        $woocommerce = $this->installedWooCommerce($snapshot);
        /** @var array<string, string> $targets target version of core and WooCommerce after this plan */
        $targets = [ComponentType::Core->value => $snapshot->core_version, ComponentType::Woocommerce->value => $woocommerce ?? ''];
        /** @var array<string, PlannedChange> $skippedTargets skipped core or WooCommerce update, by type */
        $skippedTargets = [];
        $active = $this->activePlugins($snapshot);
        $decided = [];

        foreach ($candidates as $candidate) {
            $change = $candidate['change'];
            $decision = match (true) {
                $blocked !== null => [SkipReason::SiteBlocked, $blocked],
                self::listed($excluded, $change) => [SkipReason::Excluded, 'El component és al perfil del lloc com a exclòs.'],
                self::listed($frozen, $change) => [SkipReason::Frozen, 'El component és al perfil del lloc com a congelat.'],
                $candidate['licensed'] === false => [SkipReason::NoLicense, 'No hi ha cap paquet descarregable: falta la llicència.'],
                default => $this->incompatibility($candidate, $phpVersion, $targets, $skippedTargets, $otherCoreOffers)
                    ?? $this->missingPlugin($candidate['requires_plugins'], $active)
                    ?? $this->majorChange($change),
            };

            if ($decision !== null) {
                $change = PlannedChange::skip($change, $decision[0], $decision[1]);
                if (isset($targets[$change->type->value])) {
                    $skippedTargets[$change->type->value] = $change;
                }
            } else {
                if ($change->type === ComponentType::Core || ($change->type === ComponentType::Woocommerce && $woocommerce !== null)) {
                    $targets[$change->type->value] = $change->targetVersion;
                }
                if ($candidate['requires_plugins_inferred']) {
                    $change = new PlannedChange($change->type, $change->slug, $change->currentVersion, $change->targetVersion, PlanAction::Update, null, self::INFERRED_PLUGINS);
                }
            }
            $decided[] = $change;
        }

        return new UpdatePlan($phpVersion, $phpSource, $phpSource !== PhpVersionSource::Cli, $blocked, $this->sorted($decided));
    }

    /**
     * The candidates in update order, and the core offers that were not chosen.
     *
     * @return array{0: list<array{change: PlannedChange, licensed: bool, requires_php: ?string, requires_wp: ?string, requires_wc: ?string, requires_plugins: ?list<string>, requires_plugins_inferred: bool}>, 1: list<string>}
     */
    private function candidates(SiteSnapshot $snapshot): array
    {
        // Core and WooCommerce first: later requirements are checked against their targets.
        $rows = [];
        $cores = [];
        foreach ($snapshot->pending_updates as $update) {
            if (! is_array($update)) {
                continue;
            }
            $type = is_string($update['type'] ?? null) ? ComponentType::tryFrom($update['type']) : null;
            $slug = $update['slug'] ?? null;
            $current = $update['current_version'] ?? null;
            $target = $update['new_version'] ?? null;
            if ($type === null || ! is_string($slug) || ! is_string($current) || ! is_string($target)) {
                continue;
            }
            $row = [
                'change' => new PlannedChange($type, $slug, $current, $target, PlanAction::Update),
                'licensed' => ($update['licensed'] ?? true) !== false,
                'requires_php' => self::version($update['requires_php'] ?? null),
                'requires_wp' => self::version($update['requires_wp'] ?? null),
                'requires_wc' => self::version($update['requires_wc'] ?? null),
                // Null: unknown (could not be read), never taken as "none".
                'requires_plugins' => is_array($update['requires_plugins'] ?? null) ? self::strings($update['requires_plugins']) : null,
                'requires_plugins_inferred' => ($update['requires_plugins_inferred'] ?? false) === true,
            ];
            if ($type === ComponentType::Core) {
                $cores[] = $row;
            } else {
                $rows[] = $row;
            }
        }
        // The core is one component: only one of its offers is planned.
        $core = $this->chosenCore($cores);
        $others = [];
        foreach ($cores as $offer) {
            if ($offer !== $core) {
                $others[] = $offer['change']->targetVersion;
            }
        }
        if ($core !== null) {
            $rows[] = $core;
        }

        usort($rows, fn (array $a, array $b): int => $this->rank($a['change']->type) <=> $this->rank($b['change']->type));

        return [$rows, $others];
    }

    /**
     * The highest offer that is not a major change; if all are major, the lowest one
     * (it is skipped as a major). Versions are compared as versions, never as strings.
     *
     * @template T of array{change: PlannedChange}
     *
     * @param  list<T>  $offers
     * @return T|null
     */
    private function chosenCore(array $offers): ?array
    {
        $minor = array_values(array_filter($offers, static fn (array $o): bool => ! VersionChange::isMajor(ComponentType::Core, $o['change']->currentVersion, $o['change']->targetVersion)));
        $pool = $minor === [] ? $offers : $minor;
        $best = null;
        foreach ($pool as $offer) {
            $better = $best === null || ($minor === []
                ? WpVersion::compareStrings($offer['change']->targetVersion, $best['change']->targetVersion) < 0
                : WpVersion::compareStrings($offer['change']->targetVersion, $best['change']->targetVersion) > 0);
            if ($better) {
                $best = $offer;
            }
        }

        return $best;
    }

    /**
     * What a real run would block the site for, from the snapshot's prerequisites
     * that do not need the HTTP probe (a plan run never probes). The PHP minimum only
     * counts when the PHP was verified.
     */
    private function wouldBlock(SiteSnapshot $snapshot): ?string
    {
        $stored = $snapshot->environment['prerequisites'] ?? null;
        if (! is_array($stored)) {
            return null;
        }

        $reasons = [];
        foreach ($stored as $row) {
            $check = PrerequisiteCheck::fromArray($row);
            if ($check === null || $check->passed || $check->name === PrerequisiteName::PhpVerified) {
                continue;
            }
            if ($check->name === PrerequisiteName::PhpMinimum && $snapshot->php_source === PhpVersionSource::Cli) {
                continue;
            }
            $reasons[] = $check->reason ?? "prerequisit {$check->name->value} no complert";
        }

        return $reasons === [] ? null : self::WOULD_BLOCK_PREFIX.implode(SiteBlocking::REASON_SEPARATOR, array_unique($reasons));
    }

    /**
     * @param  array{requires_php: ?string, requires_wp: ?string, requires_wc: ?string}  $candidate
     * @param  array<string, string>  $targets
     * @param  array<string, PlannedChange>  $skippedTargets
     * @param  list<string>  $otherCoreOffers
     * @return array{SkipReason, string}|null
     */
    private function incompatibility(array $candidate, string $phpVersion, array $targets, array $skippedTargets, array $otherCoreOffers): ?array
    {
        $php = $candidate['requires_php'];
        if ($php !== null) {
            $met = WpVersion::satisfies($phpVersion, $php);
            if ($met !== true) {
                return [SkipReason::PhpIncompatible, $met === null
                    ? self::unreadable('PHP', $php, $phpVersion)
                    : "Requereix PHP {$php} i el lloc té PHP {$phpVersion}."];
            }
        }

        $wp = $candidate['requires_wp'];
        $core = $targets[ComponentType::Core->value];
        if ($wp !== null) {
            $met = WpVersion::satisfies($core, $wp);
            if ($met !== true) {
                return [SkipReason::WpIncompatible, $met === null
                    ? self::unreadable('WordPress', $wp, $core)
                    : "Requereix WordPress {$wp} i el lloc tindria {$core}.".(self::because($skippedTargets[ComponentType::Core->value] ?? null, $wp) ?: self::pendingCore($otherCoreOffers, $core, $wp))];
            }
        }

        $wc = $candidate['requires_wc'];
        if ($wc !== null) {
            $installed = $targets[ComponentType::Woocommerce->value];
            if ($installed === '') {
                return [SkipReason::Dependency, "Requereix WooCommerce {$wc} i no està instal·lat o no està actiu."];
            }
            $met = WpVersion::satisfies($installed, $wc);
            if ($met !== true) {
                return [SkipReason::Dependency, $met === null
                    ? self::unreadable('WooCommerce', $wc, $installed)
                    : "Requereix WooCommerce {$wc} i el lloc tindria {$installed}.".self::because($skippedTargets[ComponentType::Woocommerce->value] ?? null, $wc)];
            }
        }

        return null;
    }

    /**
     * @param  list<string>|null  $required  null: could not be read
     * @param  array<string, bool>  $plugins  installed plugins by slug: active or not
     * @return array{SkipReason, string}|null
     */
    private function missingPlugin(?array $required, array $plugins): ?array
    {
        if ($required === null) {
            return [SkipReason::Dependency, "No s'han pogut llegir les dependències (Requires Plugins) de la versió nova: no es pot comprovar."];
        }
        foreach ($required as $slug) {
            if (($plugins[$slug] ?? null) !== true) {
                return [SkipReason::Dependency, isset($plugins[$slug])
                    ? "Requereix el plugin «{$slug}», que està instal·lat però no està actiu."
                    : "Requereix el plugin «{$slug}», que no està instal·lat."];
            }
        }

        return null;
    }

    /**
     * Why the requirement is not met when a skipped update would have met it.
     */
    private static function because(?PlannedChange $skipped, string $need): string
    {
        if ($skipped === null || WpVersion::satisfies($skipped->targetVersion, $need) !== true) {
            return '';
        }
        $name = $skipped->type === ComponentType::Core ? 'WordPress' : 'WooCommerce';
        $why = $skipped->reason === null ? '' : ': '.$skipped->reason->label();

        return " L'actualització de {$name} a {$skipped->targetVersion} s'omet{$why}.";
    }

    /**
     * A core offer that was not planned (the planner picks one) and would meet the
     * requirement: the lowest such offer, with why it is pending.
     *
     * @param  list<string>  $offers
     */
    private static function pendingCore(array $offers, string $current, string $need): string
    {
        $best = null;
        foreach ($offers as $offer) {
            if (WpVersion::satisfies($offer, $need) === true && ($best === null || WpVersion::compareStrings($offer, $best) < 0)) {
                $best = $offer;
            }
        }
        if ($best === null) {
            return '';
        }
        $why = VersionChange::isMajor(ComponentType::Core, $current, $best) ? SkipReason::MajorRequiresStaging->label() : 'no es planifica en aquesta execució';

        return " WordPress {$best} és pendent: {$why}.";
    }

    private static function unreadable(string $what, string $need, string $have): string
    {
        return WpVersion::isValid($need)
            ? "La versió de {$what} del lloc («{$have}») no es pot llegir: no es pot comprovar el requisit {$need}."
            : "El requisit de {$what} «{$need}» no és una versió llegible: no es pot comprovar.";
    }

    /**
     * Before F7 there is no staging: a major change is skipped (ADR-0005, section 2).
     *
     * @return array{SkipReason, string}|null
     */
    private function majorChange(PlannedChange $change): ?array
    {
        if (! VersionChange::isMajor($change->type, $change->currentVersion, $change->targetVersion)) {
            return null;
        }

        return [SkipReason::MajorRequiresStaging, "Canvi major ({$change->currentVersion} → {$change->targetVersion}): cal staging primer."];
    }

    /**
     * @param  list<PlannedChange>  $changes
     * @return list<PlannedChange>
     */
    private function sorted(array $changes): array
    {
        usort($changes, fn (PlannedChange $a, PlannedChange $b): int => [$this->rank($a->type), $a->slug] <=> [$this->rank($b->type), $b->slug]);

        return $changes;
    }

    private function rank(ComponentType $type): int
    {
        $index = array_search($type, self::ORDER, true);

        return $index === false ? count(self::ORDER) : $index;
    }

    /**
     * @return array<string, bool> installed plugins (WooCommerce too) by slug: whether active
     */
    private function activePlugins(SiteSnapshot $snapshot): array
    {
        $plugins = [];
        foreach ($snapshot->plugins as $plugin) {
            if (is_array($plugin) && is_string($plugin['slug'] ?? null)) {
                $plugins[$plugin['slug']] = ($plugin['active'] ?? false) === true;
            }
        }

        return $plugins;
    }

    private function installedWooCommerce(SiteSnapshot $snapshot): ?string
    {
        foreach ($snapshot->plugins as $plugin) {
            // An inactive WooCommerce does not satisfy anyone's requirement.
            if (is_array($plugin) && ($plugin['type'] ?? null) === ComponentType::Woocommerce->value && ($plugin['active'] ?? false) === true && is_string($plugin['version'] ?? null)) {
                return $plugin['version'];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $list
     */
    private static function listed(array $list, PlannedChange $change): bool
    {
        $names = [$change->slug, "{$change->type->value}:{$change->slug}"];
        if ($change->type === ComponentType::Woocommerce) {
            $names[] = "plugin:{$change->slug}";
        }

        return array_intersect($names, $list) !== [];
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_filter($values, is_string(...)));
    }

    private static function version(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
