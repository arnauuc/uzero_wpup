<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Enums\ComponentType;

/**
 * How a site profile (frozen_components, excluded_components) and --include name a
 * component: `slug` or `type:slug`, lowercase; a frozen entry may end in `@version`
 * (UZEROWPUP-51). One rule for the profile validation, the command and the planner.
 */
final class ComponentName
{
    public const string PATTERN = '/^[a-z0-9][a-z0-9_.:\/-]{0,127}$/';

    /** A frozen version: digits and dots only. */
    public const string VERSION_PATTERN = '/^\d+(\.\d+){0,3}$/';

    public const int VERSION_MAX_LENGTH = 32;

    public static function isValid(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1;
    }

    public static function isValidVersion(string $version): bool
    {
        return strlen($version) <= self::VERSION_MAX_LENGTH && preg_match(self::VERSION_PATTERN, $version) === 1;
    }

    /**
     * @return array{string, string|null} the name and the `@version`, if any
     */
    public static function split(string $entry): array
    {
        $at = strpos($entry, '@');

        return $at === false ? [$entry, null] : [substr($entry, 0, $at), substr($entry, $at + 1)];
    }

    /**
     * Every name that a profile entry may use for a component. WooCommerce is also a plugin.
     *
     * @return list<string>
     */
    public static function namesOf(ComponentType $type, string $slug): array
    {
        $names = [$slug, "{$type->value}:{$slug}"];
        if ($type === ComponentType::Woocommerce) {
            $names[] = "plugin:{$slug}";
        }

        return $names;
    }

    /**
     * Whether two entries can name the same component: same slug and, if both give a type, the same type.
     */
    public static function overlaps(string $a, string $b): bool
    {
        [$typeA, $slugA] = self::typeAndSlug(self::split($a)[0]);
        [$typeB, $slugB] = self::typeAndSlug(self::split($b)[0]);

        return $slugA === $slugB && ($typeA === null || $typeB === null || $typeA === $typeB);
    }

    /**
     * @return array{string|null, string}
     */
    private static function typeAndSlug(string $name): array
    {
        $colon = strpos($name, ':');

        return $colon === false ? [null, $name] : [substr($name, 0, $colon), substr($name, $colon + 1)];
    }
}
