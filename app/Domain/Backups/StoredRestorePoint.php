<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Models\Backup;
use Carbon\CarbonImmutable;

/**
 * A restore point as it is registered: the live `backups` rows of one site
 * under one key directory ("site-{id}/run{run id}" or "site-{id}/manual-…"),
 * at every destination.
 */
final readonly class StoredRestorePoint
{
    /**
     * @param  non-empty-list<Backup>  $rows
     */
    public function __construct(
        public int $siteId,
        public string $directory,
        public array $rows,
    ) {}

    public function runId(): ?int
    {
        foreach ($this->rows as $row) {
            if ($row->run_id !== null) {
                return $row->run_id;
            }
        }

        return null;
    }

    public function createdAt(): CarbonImmutable
    {
        $first = $this->rows[0]->created_at;
        foreach ($this->rows as $row) {
            $first = $row->created_at->lessThan($first) ? $row->created_at : $first;
        }

        return $first;
    }

    /** Tie-break between points made in the same second. */
    public function firstId(): int
    {
        return min(array_map(static fn (Backup $row): int => $row->id, $this->rows));
    }

    /**
     * Expired once every row is: a row without expiry keeps the whole point.
     */
    public function isExpired(CarbonImmutable $now): bool
    {
        foreach ($this->rows as $row) {
            if ($row->expires_at === null || $row->expires_at->greaterThanOrEqualTo($now)) {
                return false;
            }
        }

        return true;
    }

    public function isProtected(): bool
    {
        foreach ($this->rows as $row) {
            if ($row->protected) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $localTargetIds  the site-local destinations
     */
    public function isVerifiedLocally(array $localTargetIds): bool
    {
        foreach ($this->rows as $row) {
            if ($row->verified_at !== null && in_array($row->backup_target_id, $localTargetIds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    public function backupIds(): array
    {
        return array_map(static fn (Backup $row): int => $row->id, $this->rows);
    }
}
