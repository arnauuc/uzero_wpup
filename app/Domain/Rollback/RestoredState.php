<?php

declare(strict_types=1);

namespace App\Domain\Rollback;

use App\Domain\Drivers\Data\InstalledComponents;
use App\Enums\ChangeResult;
use App\Enums\ComponentType;
use App\Models\RunChange;
use App\Models\SiteSnapshot;

/**
 * Whether the versions on the site after a files rollback are those from
 * before the update (UZEROWPUP-57). Checked: every component the update
 * changed or tried to change (its run_changes rows), and, with the run's
 * inventory, any plugin or theme that appeared or disappeared since it (an
 * update may bring a dependency it did not record). The expected version is
 * the inventory's when there is one, otherwise the row's version before
 * (none: it must be gone). Other components are left alone, so unrelated
 * drift (a cache drop-in written again) does not fail the rollback. Versions
 * only: whether a plugin is active lives in the database.
 */
final class RestoredState
{
    private const string CORE = 'core';

    /** Types whose appearance or disappearance is checked against the inventory. */
    private const array PRESENCE_TYPES = [ComponentType::Plugin, ComponentType::Woocommerce, ComponentType::Theme];

    /**
     * @param  iterable<RunChange>  $changes  the update rows of the run
     * @return list<string> one line per difference (Catalan); empty when it matches
     */
    public static function differences(InstalledComponents $now, ?SiteSnapshot $before, iterable $changes): array
    {
        $actual = [self::CORE => $now->coreVersion];
        $present = [];
        foreach ($now->components as $component) {
            $key = self::key($component->type, $component->slug);
            $actual[$key] = $component->version;
            if (in_array($component->type, self::PRESENCE_TYPES, true)) {
                $present[$key] = true;
            }
        }

        $expected = [];
        foreach ($changes as $change) {
            if ($change->component_type !== ComponentType::Translation && $change->result !== ChangeResult::Skipped) {
                $expected[self::key($change->component_type, $change->slug)] = $change->version_before;
            }
        }
        if ($before !== null) {
            [$inventory, $listed] = self::fromSnapshot($before);
            $keys = [...array_keys($expected), ...array_keys(array_diff_key($present, $listed)), ...array_keys(array_diff_key($listed, $present))];
            $expected = [];
            foreach (array_unique($keys) as $key) {
                $expected[$key] = $key === self::CORE ? $before->core_version : ($inventory[$key] ?? null);
            }
        }
        ksort($expected);

        $differences = [];
        foreach ($expected as $key => $want) {
            $have = $actual[$key] ?? null;
            if ($want !== $have) {
                $differences[] = sprintf('%s: abans %s, ara %s.', $key, $want ?? 'no hi era', $have ?? 'no hi és');
            }
        }

        return $differences;
    }

    /**
     * The components of the inventory by key, and the keys of its plugins and themes.
     *
     * @return array{array<string, string>, array<string, true>}
     */
    private static function fromSnapshot(SiteSnapshot $snapshot): array
    {
        $expected = $listed = [];
        $lists = [[$snapshot->plugins, ComponentType::Plugin], [$snapshot->themes, ComponentType::Theme], [$snapshot->dropins, ComponentType::Dropin], [$snapshot->mu_plugins, ComponentType::MuPlugin]];
        foreach ($lists as [$rows, $default]) {
            foreach ($rows as $row) {
                if (! is_array($row) || ! is_string($row['slug'] ?? null) || ! is_string($row['version'] ?? null)) {
                    continue;
                }
                $type = is_string($row['type'] ?? null) ? (ComponentType::tryFrom($row['type']) ?? $default) : $default;
                $key = self::key($type, $row['slug']);
                $expected[$key] = $row['version'];
                if (in_array($type, self::PRESENCE_TYPES, true)) {
                    $listed[$key] = true;
                }
            }
        }

        return [$expected, $listed];
    }

    private static function key(ComponentType $type, string $slug): string
    {
        return $type === ComponentType::Core ? self::CORE : "{$type->value}:{$slug}";
    }
}
