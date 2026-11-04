<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Enums\ComponentType;
use App\Enums\PhpVersionSource;
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
 * the core is `core:wordpress` or `wordpress`.
 *
 * Rules, the first that matches wins: site blocked, excluded, frozen, unlicensed,
 * PHP required by the update above the site's, WordPress required above the
 * core the site will have, WooCommerce required above the one the site will have,
 * major version (ADR-0005, before F7: needs staging).
 */
final readonly class UpdatePlanner
{
    /**
     * @param  list<string>  $order  component types in update order (wpup.planning.order)
     */
    public function __construct(private array $order) {}

    public function plan(SiteSnapshot $snapshot, Site $site, ?SiteProfile $profile, string $phpVersion, PhpVersionSource $phpSource): UpdatePlan
    {
        $blocked = $site->blocked_reason;
        $frozen = $profile === null ? [] : self::strings($profile->frozen_components);
        $excluded = $profile === null ? [] : self::strings($profile->excluded_components);

        $candidates = $this->candidates($snapshot);

        /** @var array<string, string> $targets target version of core and WooCommerce after this plan */
        $targets = [ComponentType::Core->value => $snapshot->core_version, ComponentType::Woocommerce->value => $this->installedWooCommerce($snapshot) ?? ''];
        $decided = [];

        foreach ($candidates as $candidate) {
            $change = $candidate['change'];
            $decision = match (true) {
                $blocked !== null => [SkipReason::SiteBlocked, $blocked],
                self::listed($excluded, $change) => [SkipReason::Excluded, 'El component és al perfil del lloc com a exclòs.'],
                self::listed($frozen, $change) => [SkipReason::Frozen, 'El component és al perfil del lloc com a congelat.'],
                $candidate['licensed'] === false => [SkipReason::NoLicense, 'No hi ha cap paquet descarregable: falta la llicència.'],
                default => $this->incompatibility($candidate, $change, $phpVersion, $targets) ?? $this->majorChange($change),
            };

            if ($decision !== null) {
                $change = PlannedChange::skip($change, $decision[0], $decision[1]);
            } elseif (isset($targets[$change->type->value])) {
                $targets[$change->type->value] = $change->targetVersion;
            }
            $decided[] = $change;
        }

        return new UpdatePlan($phpVersion, $phpSource, $phpSource !== PhpVersionSource::Cli, $blocked, $this->sorted($decided));
    }

    /**
     * @return list<array{change: PlannedChange, licensed: bool, requires_php: ?string, requires_wp: ?string, requires_wc: ?string}>
     */
    private function candidates(SiteSnapshot $snapshot): array
    {
        // Core and WooCommerce first: later requirements are checked against their targets.
        $rows = [];
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
            $rows[] = [
                'change' => new PlannedChange($type, $slug, $current, $target, PlanAction::Update),
                'licensed' => ($update['licensed'] ?? true) !== false,
                'requires_php' => self::version($update['requires_php'] ?? null),
                'requires_wp' => self::version($update['requires_wp'] ?? null),
                'requires_wc' => self::version($update['requires_wc'] ?? null),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $this->rank($a['change']->type) <=> $this->rank($b['change']->type));

        return $rows;
    }

    /**
     * @param  array{licensed: bool, requires_php: ?string, requires_wp: ?string, requires_wc: ?string}  $candidate
     * @param  array<string, string>  $targets
     * @return array{SkipReason, string}|null
     */
    private function incompatibility(array $candidate, PlannedChange $change, string $phpVersion, array $targets): ?array
    {
        $php = $candidate['requires_php'];
        if ($php !== null && version_compare($phpVersion, $php, '<')) {
            return [SkipReason::PhpIncompatible, "Requereix PHP {$php} i el lloc té PHP {$phpVersion}."];
        }

        $wp = $candidate['requires_wp'];
        $core = $targets[ComponentType::Core->value];
        if ($wp !== null && version_compare($core, $wp, '<')) {
            return [SkipReason::WpIncompatible, "Requereix WordPress {$wp} i el lloc tindria {$core}."];
        }

        $wc = $candidate['requires_wc'];
        if ($wc !== null) {
            $installed = $targets[ComponentType::Woocommerce->value];
            if ($installed === '') {
                return [SkipReason::Dependency, "Requereix WooCommerce {$wc} i no està instal·lat."];
            }
            if (version_compare($installed, $wc, '<')) {
                return [SkipReason::Dependency, "Requereix WooCommerce {$wc} i el lloc tindria {$installed}."];
            }
        }

        return null;
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
        $index = array_search($type->value, $this->order, true);

        return $index === false ? count($this->order) : $index;
    }

    private function installedWooCommerce(SiteSnapshot $snapshot): ?string
    {
        foreach ($snapshot->plugins as $plugin) {
            if (is_array($plugin) && ($plugin['type'] ?? null) === ComponentType::Woocommerce->value && is_string($plugin['version'] ?? null)) {
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
        return in_array($change->slug, $list, true) || in_array("{$change->type->value}:{$change->slug}", $list, true);
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
