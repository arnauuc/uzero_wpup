<?php

declare(strict_types=1);

namespace App\Domain\Sites;

use DomainException;

/**
 * Parses the maintenance window days spec «1-5,7» (1 = Monday, 7 = Sunday).
 * Shared by the site commands and the bulk import.
 */
final class WindowDays
{
    /**
     * @return list<int>
     *
     * @throws DomainException
     */
    public static function parse(string $spec): array
    {
        $days = [];
        foreach (explode(',', $spec) as $part) {
            if (preg_match('/^([1-7])(?:-([1-7]))?$/', trim($part), $m) !== 1) {
                throw new DomainException('Els dies de la finestra tenen el format «1-5,7» (1 = dilluns, 7 = diumenge).');
            }
            array_push($days, ...range((int) $m[1], (int) ($m[2] ?? $m[1])));
        }

        return array_values(array_unique($days));
    }
}
