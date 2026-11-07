<?php

declare(strict_types=1);

namespace App\Domain\Sites;

use DomainException;

/**
 * Parses the maintenance window of the site commands and the bulk import, so
 * both read it the same way:
 *
 * - days: «1-5,7» (1 = Monday, 7 = Sunday), the Catalan abbreviations
 *   «dl-dv,dg» (dl dt dc dj dv ds dg) or «cada dia». A range of day names may
 *   wrap past Sunday («dv-dl» = Friday to Monday). A numeric range keeps the
 *   meaning it always had: «5-1» is the days 1 to 5, never a wrap.
 * - a whole window: days, a space and «HH:MM-HH:MM» («dl-dv 10:00-13:00»,
 *   «cada dia 01:00-06:00»), as MaintenanceWindow::label() prints it too.
 *   Times have two-digit hours, as the separate options require.
 *
 * Only the syntax is checked here: MaintenanceWindow::errors() validates the values.
 */
final class WindowDays
{
    private const array NAMES = ['dl' => 1, 'dt' => 2, 'dc' => 3, 'dj' => 4, 'dv' => 5, 'ds' => 6, 'dg' => 7];

    private const string DAY = '(?:[1-7]|dl|dt|dc|dj|dv|ds|dg)';

    /**
     * @return list<int>
     *
     * @throws DomainException
     */
    public static function parse(string $spec): array
    {
        $spec = mb_strtolower(trim($spec));
        if ($spec === 'cada dia') {
            return [1, 2, 3, 4, 5, 6, 7];
        }

        $days = [];
        foreach (explode(',', $spec) as $part) {
            if (preg_match('/^('.self::DAY.')(?:\s*-\s*('.self::DAY.'))?$/', trim($part), $m) !== 1) {
                throw new DomainException('Els dies de la finestra tenen el format «1-5,7» o «dl-dv,dg» (1 o dl = dilluns, 7 o dg = diumenge), o «cada dia».');
            }
            $from = self::day($m[1]);
            $to = self::day($m[2] ?? $m[1]);
            // Only day names wrap past Sunday; numbers keep their old meaning (range() either way).
            $wraps = $from > $to && isset(self::NAMES[$m[1]], self::NAMES[$m[2] ?? '']);
            array_push($days, ...($wraps ? [...range($from, 7), ...range(1, $to)] : range($from, $to)));
        }

        return array_values(array_unique($days));
    }

    /**
     * «dl-dv 10:00-13:00»: the days and the start and end times.
     *
     * @return array{days: list<int>, start: string, end: string}
     *
     * @throws DomainException
     */
    public static function parseWindow(string $spec): array
    {
        if (preg_match('/^(.+?)\s+(\d{2}:\d{2})\s*[-–]\s*(\d{2}:\d{2})$/u', trim($spec), $m) !== 1) {
            throw new DomainException('La finestra té el format «dies HH:MM-HH:MM», p. ex. «dl-dv 10:00-13:00» o «cada dia 01:00-06:00».');
        }

        return ['days' => self::parse($m[1]), 'start' => $m[2], 'end' => $m[3]];
    }

    private static function day(string $token): int
    {
        return self::NAMES[$token] ?? (int) $token;
    }
}
