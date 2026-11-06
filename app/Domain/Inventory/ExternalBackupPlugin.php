<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * A backup plugin found on a site (UZEROWPUP-133). $exposesDate says whether
 * the plugin is known to keep the time of its last backup in a readable place
 * (config); lastBackupAt is null when it does not or when it could not be read.
 * $lastBackupOk is false when the last backup is recorded as failed, null when
 * unknown (UpdraftPlus records failed runs there too).
 */
final readonly class ExternalBackupPlugin
{
    public function __construct(
        public string $slug,
        public string $name,
        public bool $active,
        public bool $exposesDate,
        public ?CarbonImmutable $lastBackupAt,
        public ?bool $lastBackupOk = null,
    ) {}

    /**
     * True when the plugin should have told the date and it could not be read.
     */
    public function dateUnreadable(): bool
    {
        return $this->exposesDate && $this->lastBackupAt === null;
    }

    /**
     * @return array{slug: string, name: string, active: bool, exposes_date: bool, last_backup_at: string|null, last_backup_ok: bool|null}
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'active' => $this->active,
            'exposes_date' => $this->exposesDate,
            'last_backup_at' => $this->lastBackupAt?->utc()->toIso8601String(),
            'last_backup_ok' => $this->lastBackupOk,
        ];
    }

    /**
     * Rebuilds a plugin from a stored snapshot row; null if the row is malformed.
     */
    public static function fromArray(mixed $row): ?self
    {
        if (! is_array($row) || ! is_string($row['slug'] ?? null) || ! is_string($row['name'] ?? null)) {
            return null;
        }
        $at = null;
        if (is_string($row['last_backup_at'] ?? null)) {
            try {
                $at = CarbonImmutable::parse($row['last_backup_at']);
            } catch (Throwable) {
                $at = null;
            }
        }

        return new self($row['slug'], $row['name'], ($row['active'] ?? false) === true, ($row['exposes_date'] ?? false) === true, $at, is_bool($row['last_backup_ok'] ?? null) ? $row['last_backup_ok'] : null);
    }
}
