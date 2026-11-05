<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Enums\BackupKind;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use InvalidArgumentException;

/**
 * Filters of the backup listing (`wpup backup:list`, and the web panel later).
 * Dates filter on the creation of the file; `until` is the day after, exclusive (the whole
 * day is included).
 */
final readonly class BackupListingFilter
{
    private const string DATE_FORMAT = 'Y-m-d';

    /**
     * @param  list<string>  $sites  site names
     * @param  list<string>  $targets  destination names
     */
    public function __construct(
        public array $sites = [],
        public array $targets = [],
        public ?CarbonImmutable $since = null,
        public ?CarbonImmutable $until = null,
        public ?BackupKind $kind = null,
        public bool $includeDeleted = false,
    ) {}

    /**
     * Validates raw operator input (Catalan errors).
     *
     * @param  list<string>  $sites
     * @param  list<string>  $targets
     *
     * @throws InvalidArgumentException
     */
    public static function fromInput(array $sites, array $targets, ?string $since, ?string $until, ?string $kind, bool $includeDeleted): self
    {
        $unknownSites = array_values(array_diff($sites, self::strings(Site::query()->whereIn('name', $sites)->pluck('name')->all())));
        if ($unknownSites !== []) {
            throw new InvalidArgumentException('Lloc desconegut: '.implode(', ', $unknownSites).'.');
        }
        $unknownTargets = array_values(array_diff($targets, self::strings(BackupTargetRecord::query()->whereIn('name', $targets)->pluck('name')->all())));
        if ($unknownTargets !== []) {
            throw new InvalidArgumentException('Destinació desconeguda: '.implode(', ', $unknownTargets).'.');
        }

        $kindEnum = $kind === null ? null : BackupKind::tryFrom($kind);
        if ($kind !== null && $kindEnum === null) {
            throw new InvalidArgumentException("Tipus de fitxer no vàlid: {$kind} (admesos: ".implode(', ', array_map(static fn (BackupKind $k): string => $k->value, BackupKind::cases())).').');
        }

        $from = self::date($since, '--since')?->startOfDay();
        $to = self::date($until, '--until')?->addDay();
        if ($from !== null && $to !== null && $from->greaterThanOrEqualTo($to)) {
            throw new InvalidArgumentException('--since no pot ser posterior a --until.');
        }

        return new self($sites, $targets, $from, $to, $kindEnum, $includeDeleted);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_filter($values, is_string(...)));
    }

    private static function date(?string $value, string $option): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!'.self::DATE_FORMAT, $value);
        } catch (InvalidFormatException) {
            $date = null;
        }
        if ($date === null || $date->format(self::DATE_FORMAT) !== $value) {
            throw new InvalidArgumentException("Data no vàlida a {$option}: {$value} (format AAAA-MM-DD).");
        }

        return $date;
    }
}
