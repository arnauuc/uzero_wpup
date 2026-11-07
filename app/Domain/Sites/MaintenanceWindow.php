<?php

declare(strict_types=1);

namespace App\Domain\Sites;

use App\Models\Site;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A site's maintenance window (ADR-0006 §1): days of the week (1 = Monday …
 * 7 = Sunday) on which it may START, and a start and end time in the tool's
 * time zone (app.timezone, Europe/Madrid). A window whose end is not after its
 * start crosses midnight: it belongs to the day it starts on. Sites without one
 * of their own use wpup.sites.default_window.
 *
 * Wall-clock times: daylight saving changes move the window with the clock.
 * On the night the clocks go forward, a time inside the skipped hour is the
 * same time one hour later (02:30 ⇒ 03:30), as PHP resolves it; a window that
 * is left with no time at all that night is closed.
 *
 * The same rules validate a window when a site is added, edited or imported
 * (SiteAdministration) and when an update waits for it.
 */
final readonly class MaintenanceWindow
{
    private const string TIME = '/^(?:[01]\d|2[0-3]):[0-5]\d$/D';

    /**
     * @param  list<int>  $days
     *
     * @throws InvalidArgumentException when the window is not valid
     */
    public function __construct(
        public array $days,
        public string $start,
        public string $end,
        public string $timezone,
    ) {
        $errors = self::errors($days, $start, $end);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
    }

    /**
     * Why a window is not valid (operator-facing); empty when it is.
     *
     * @param  list<int>  $days
     * @param  int  $minimumMinutes  the shortest window an update can start in (wpup.updates.window.minimum_minutes)
     * @return list<string>
     */
    public static function errors(array $days, string $start, string $end, int $minimumMinutes = 0): array
    {
        $errors = [];
        if ($days === [] || array_filter($days, static fn (int $d): bool => $d < 1 || $d > 7) !== []) {
            $errors[] = 'Els dies de la finestra han de ser entre 1 (dilluns) i 7 (diumenge).';
        }
        if (preg_match(self::TIME, $start) !== 1 || preg_match(self::TIME, $end) !== 1) {
            $errors[] = "L'inici i el final de la finestra han de tenir el format HH:MM.";
        } elseif ($start === $end) {
            $errors[] = "L'inici i el final de la finestra no poden coincidir.";
        } elseif (self::minutesBetween($start, $end) < $minimumMinutes) {
            $errors[] = "La finestra ha de durar com a mínim {$minimumMinutes} minuts, el temps mínim per començar una actualització.";
        }

        return $errors;
    }

    /**
     * @param  array<mixed>  $default  wpup.sites.default_window
     *
     * @throws InvalidArgumentException when the stored window is malformed
     */
    public static function of(Site $site, array $default, string $timezone): self
    {
        $window = $site->maintenance_window ?? $default;
        $days = $window['days'] ?? null;
        $start = $window['start'] ?? null;
        $end = $window['end'] ?? null;
        if (! is_array($days) || ! is_string($start) || ! is_string($end)) {
            throw new InvalidArgumentException("La finestra del lloc {$site->name} no té el format esperat.");
        }

        return new self(array_values(array_filter($days, is_int(...))), $start, $end, $timezone);
    }

    /**
     * Length on the wall clock, in minutes.
     */
    public function lengthMinutes(): int
    {
        return self::minutesBetween($this->start, $this->end);
    }

    /**
     * When the window open at $moment closes; null when it is closed.
     */
    public function closesAt(CarbonImmutable $moment): ?CarbonImmutable
    {
        $local = $moment->setTimezone($this->timezone);
        // A window that crosses midnight may have started the day before.
        foreach ([$local->subDay(), $local] as $day) {
            [$opens, $closes] = $this->interval($day);
            if (in_array($day->dayOfWeekIso, $this->days, true) && $moment->greaterThanOrEqualTo($opens) && $moment->lessThan($closes)) {
                return $closes;
            }
        }

        return null;
    }

    /**
     * The first opening strictly after $moment.
     */
    public function nextOpening(CarbonImmutable $moment): CarbonImmutable
    {
        $local = $moment->setTimezone($this->timezone)->startOfDay();
        for ($offset = 0; $offset <= 8; $offset++) {
            $day = $local->addDays($offset);
            [$opens, $closes] = $this->interval($day);
            if (in_array($day->dayOfWeekIso, $this->days, true) && $closes->greaterThan($opens) && $opens->greaterThan($moment)) {
                return $opens;
            }
        }

        // Unreachable: at least one day a week is in the window.
        return $local->addDays(9);
    }

    /**
     * Human form for the operator: «dl,dt 01:00–06:00» or «cada dia 01:00–06:00».
     */
    public function label(): string
    {
        $names = [1 => 'dl', 2 => 'dt', 3 => 'dc', 4 => 'dj', 5 => 'dv', 6 => 'ds', 7 => 'dg'];
        $days = $this->days;
        sort($days);

        return ($days === [1, 2, 3, 4, 5, 6, 7] ? 'cada dia' : implode(',', array_map(static fn (int $d): string => $names[$d], $days)))
            ." {$this->start}–{$this->end}";
    }

    /**
     * Opening and closing of the window that starts on $day; equal when it has no time that day.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function interval(CarbonImmutable $day): array
    {
        $date = $day->setTimezone($this->timezone)->format('Y-m-d');
        $opens = self::at($date, $this->start, $this->timezone);
        // Crossing midnight is decided on the wall clock, never on instants a DST change moved.
        $endDate = $this->end <= $this->start ? CarbonImmutable::parse($date, $this->timezone)->addDay()->format('Y-m-d') : $date;
        $closes = self::at($endDate, $this->end, $this->timezone);

        return [$opens, $closes->greaterThan($opens) ? $closes : $opens];
    }

    private static function at(string $date, string $time, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$time}", $timezone)
            ?? throw new InvalidArgumentException("Hora de la finestra il·legible: {$time}.");
    }

    private static function minutesBetween(string $start, string $end): int
    {
        $minutes = static fn (string $t): int => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
        $length = $minutes($end) - $minutes($start);

        return $length > 0 ? $length : $length + 1440;
    }
}
