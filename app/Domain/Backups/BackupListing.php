<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Runs\ReportVersion;
use App\Models\Backup;
use Illuminate\Database\Eloquent\Collection;

/**
 * Registered backup files, newest first (read only). Shared by the CLI and the web panel.
 */
final class BackupListing
{
    /**
     * @return Collection<int, Backup> with site and backupTarget loaded
     */
    public function list(BackupListingFilter $filter): Collection
    {
        return Backup::query()
            ->with(['site', 'backupTarget'])
            ->when(! $filter->includeDeleted, static fn ($q) => $q->whereNull('deleted_at'))
            ->when($filter->sites !== [], static fn ($q) => $q->whereHas('site', static fn ($s) => $s->whereIn('name', $filter->sites)))
            ->when($filter->targets !== [], static fn ($q) => $q->whereHas('backupTarget', static fn ($t) => $t->whereIn('name', $filter->targets)))
            ->when($filter->kind !== null, static fn ($q) => $q->where('kind', $filter->kind))
            ->when($filter->since !== null, static fn ($q) => $q->where('created_at', '>=', $filter->since))
            ->when($filter->until !== null, static fn ($q) => $q->where('created_at', '<=', $filter->until))
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The `--json` document.
     *
     * @param  Collection<int, Backup>  $backups
     * @return array{version: int, backups: list<array<string, mixed>>}
     */
    public function document(Collection $backups): array
    {
        return [
            'version' => ReportVersion::read('wpup.backups.report_version'),
            'backups' => array_values($backups->map(static fn (Backup $b): array => [
                'id' => $b->id,
                'site' => $b->site?->name,
                'run_id' => $b->run_id,
                'kind' => $b->kind->value,
                'target' => $b->backupTarget?->name,
                'key' => $b->path,
                'size_bytes' => $b->size_bytes,
                'sha256' => $b->sha256,
                'verified' => $b->verified_at !== null,
                'created_at' => $b->created_at->toIso8601String(),
                'expires_at' => $b->expires_at?->toIso8601String(),
                'protected' => $b->protected,
                'deleted' => $b->deleted_at !== null,
            ])->all()),
        ];
    }
}
