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
 * before the update (UZEROWPUP-57). The reference is the inventory the run
 * took before its restore point: every component (core, plugins, themes,
 * drop-ins, must-use plugins) must be there with the same version, and
 * nothing else. Without that inventory, each change the update made (or
 * tried) must be back at its version before, and a component it added must
 * be gone. Versions only: whether a plugin is active lives in the database.
 */
final class RestoredState
{
    private const string CORE = 'core';

    /**
     * @param  iterable<RunChange>  $changes  the update rows of the run
     * @return list<string> one line per difference (Catalan); empty when it matches
     */
    public static function differences(InstalledComponents $now, ?SiteSnapshot $before, iterable $changes): array
    {
        $actual = [self::CORE => $now->coreVersion];
        foreach ($now->components as $component) {
            $actual[self::key($component->type, $component->slug)] = $component->version;
        }

        $expected = $before !== null ? self::fromSnapshot($before) : self::fromChanges($changes);
        $keys = $before !== null ? array_keys($expected + $actual) : array_keys($expected);
        sort($keys);

        $differences = [];
        foreach ($keys as $key) {
            $want = $expected[$key] ?? null;
            $have = $actual[$key] ?? null;
            if ($want !== $have) {
                $differences[] = sprintf('%s: abans %s, ara %s.', $key, $want ?? 'no hi era', $have ?? 'no hi és');
            }
        }

        return $differences;
    }

    /**
     * @return array<string, string>
     */
    private static function fromSnapshot(SiteSnapshot $snapshot): array
    {
        $expected = [self::CORE => $snapshot->core_version];
        $lists = [
            [$snapshot->plugins, ComponentType::Plugin],
            [$snapshot->themes, ComponentType::Theme],
            [$snapshot->dropins, ComponentType::Dropin],
            [$snapshot->mu_plugins, ComponentType::MuPlugin],
        ];
        foreach ($lists as [$rows, $default]) {
            foreach ($rows as $row) {
                if (! is_array($row) || ! is_string($row['slug'] ?? null) || ! is_string($row['version'] ?? null)) {
                    continue;
                }
                $type = is_string($row['type'] ?? null) ? (ComponentType::tryFrom($row['type']) ?? $default) : $default;
                $expected[self::key($type, $row['slug'])] = $row['version'];
            }
        }

        return $expected;
    }

    /**
     * @param  iterable<RunChange>  $changes
     * @return array<string, string|null> null: must not be there
     */
    private static function fromChanges(iterable $changes): array
    {
        $expected = [];
        foreach ($changes as $change) {
            if ($change->component_type === ComponentType::Translation || $change->result === ChangeResult::Skipped) {
                continue;
            }
            $expected[self::key($change->component_type, $change->slug)] = $change->version_before;
        }

        return $expected;
    }

    private static function key(ComponentType $type, string $slug): string
    {
        return $type === ComponentType::Core ? self::CORE : "{$type->value}:{$slug}";
    }
}
