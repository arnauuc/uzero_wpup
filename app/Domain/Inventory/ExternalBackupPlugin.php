<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * A backup plugin found on a site (UZEROWPUP-133). lastBackupAt is null when
 * the plugin does not expose it or it could not be read.
 */
final readonly class ExternalBackupPlugin
{
    public function __construct(
        public string $slug,
        public string $name,
        public bool $active,
        public ?CarbonImmutable $lastBackupAt,
    ) {}

    /**
     * @return array{slug: string, name: string, active: bool, last_backup_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'active' => $this->active,
            'last_backup_at' => $this->lastBackupAt?->utc()->toIso8601String(),
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

        return new self($row['slug'], $row['name'], ($row['active'] ?? false) === true, $at);
    }
}
