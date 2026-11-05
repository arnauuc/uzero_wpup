<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Enums\RunStatus;

/**
 * One site in the output of `wpup backup`: the run and the restore point it left.
 *
 * @phpstan-type PointFile array{id: int, backup_target_id: int, kind: string, key: string, size_bytes: int, sha256: string, verified: bool, expires_at: ?string}
 */
final readonly class BackupRow
{
    /**
     * @param  list<PointFile>  $files
     * @param  list<string>  $warnings
     * @param  array<int, string>  $targetNames  backup_targets.id => name
     * @param  string|null  $note  what a dry run would do
     */
    public function __construct(
        public string $site,
        public string $url,
        public int $runId,
        public RunStatus $runStatus,
        public ?string $failure,
        public ?string $directory,
        public array $files,
        public array $warnings,
        public array $targetNames,
        public ?string $note = null,
    ) {}

    public function hasPoint(): bool
    {
        return $this->files !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'site' => $this->site,
            'url' => $this->url,
            'run_id' => $this->runId,
            'status' => $this->runStatus->value,
            'failure' => $this->failure,
            'note' => $this->note,
            'directory' => $this->directory,
            'files' => array_map(fn (array $f): array => [...$f, 'target' => $this->targetNames[$f['backup_target_id']] ?? null], $this->files),
            'warnings' => $this->warnings,
        ];
    }
}
