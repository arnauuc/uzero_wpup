<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Enums\BackupKind;
use App\Models\Backup;

/**
 * A restore point made by RestorePointService (ADR-0009): its `backups` rows,
 * one per file and destination, and operator-readable warnings (Catalan). The
 * site-local rows are always verified; an external row may not be (a warning
 * says why).
 */
final readonly class RestorePoint
{
    /**
     * @param  int  $localTargetId  the site-local destination (backup_targets.id)
     * @param  string  $directory  key prefix of its objects at every destination
     * @param  list<Backup>  $backups
     * @param  list<string>  $warnings
     * @param  bool  $reused  a retried step found it already complete and verified
     */
    public function __construct(
        public int $siteId,
        public ?int $runId,
        public int $localTargetId,
        public string $directory,
        public array $backups,
        public array $warnings = [],
        public bool $reused = false,
    ) {}

    /**
     * The verified file of $kind at the site-local destination.
     */
    public function local(BackupKind $kind): ?Backup
    {
        foreach ($this->backups as $backup) {
            if ($backup->backup_target_id === $this->localTargetId && $backup->kind === $kind) {
                return $backup;
            }
        }

        return null;
    }

    /**
     * For run step data and reports: no paths beyond the keys, no secrets.
     *
     * @return array{directory: string, reused: bool, backups: list<array{id: int, backup_target_id: int, kind: string, key: string, size_bytes: int, sha256: string, verified: bool, expires_at: ?string}>, warnings: list<string>}
     */
    public function summary(): array
    {
        return [
            'directory' => $this->directory,
            'reused' => $this->reused,
            'backups' => array_map(static fn (Backup $b): array => [
                'id' => $b->id,
                'backup_target_id' => $b->backup_target_id,
                'kind' => $b->kind->value,
                'key' => $b->path,
                'size_bytes' => $b->size_bytes,
                'sha256' => $b->sha256,
                'verified' => $b->verified_at !== null,
                'expires_at' => $b->expires_at?->toIso8601String(),
            ], $this->backups),
            'warnings' => $this->warnings,
        ];
    }
}
