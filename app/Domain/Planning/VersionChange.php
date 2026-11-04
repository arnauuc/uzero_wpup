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
     * a change of the first number. A version that cannot be read counts as major.
     */
    public static function isMajor(ComponentType $type, string $from, string $to): bool
    {
        $pattern = $type === ComponentType::Core ? '/^(\d+\.\d+)/' : '/^(\d+)/';
        if (preg_match($pattern, $from, $a) !== 1 || preg_match($pattern, $to, $b) !== 1) {
            return true;
        }

        return $a[1] !== $b[1];
    }
}
