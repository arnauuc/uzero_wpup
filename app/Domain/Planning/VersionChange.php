<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Enums\ComponentType;

/**
 * What counts as a major update for each component type (ADR-0005).
 */
final class VersionChange
{
    /**
     * Core: a change of `x.y`. Everything else (WooCommerce `X.0`, plugins, themes):
     * a change of the first number. Missing components are zero (7 → 7.0.1 is not
     * major). A version that cannot be read counts as major.
     */
    public static function isMajor(ComponentType $type, string $from, string $to): bool
    {
        $a = WpVersion::parse($from);
        $b = WpVersion::parse($to);
        if ($a === null || $b === null) {
            return true;
        }
        $count = $type === ComponentType::Core ? 2 : 1;

        return $a->head($count) !== $b->head($count);
    }
}
