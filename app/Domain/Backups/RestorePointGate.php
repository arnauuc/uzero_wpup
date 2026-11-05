<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Enums\BackupKind;
use App\Enums\BackupTargetType;
use App\Models\Backup;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Run;
use Carbon\CarbonImmutable;

/**
 * No update without a verified restore point (AGENTS.md rule 3, ADR-0009). The
 * update pipeline asks right before it changes the site: the run must have,
 * at the site-local destination and under one restore point (same key
 * directory), both a database dump and a code archive whose checksum was
 * verified there, not purged nor expired. External copies never count.
 */
final class RestorePointGate
{
    public function isVerified(Run $run): bool
    {
        if ($run->site_id === null) {
            return false;
        }

        $now = CarbonImmutable::now();
        $rows = Backup::query()
            ->where('run_id', $run->id)
            ->where('site_id', $run->site_id)
            ->whereIn('backup_target_id', BackupTargetRecord::query()->select('id')->where('type', BackupTargetType::SiteLocal))
            ->whereNotNull('verified_at')
            ->whereNull('deleted_at')
            ->where(static fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->get(['kind', 'path', 'sha256']);

        $kindsByPoint = [];
        foreach ($rows as $row) {
            if (preg_match('/^[0-9a-f]{64}$/D', $row->sha256) === 1) {
                $kindsByPoint[dirname($row->path)][$row->kind->value] = true;
            }
        }
        foreach ($kindsByPoint as $kinds) {
            if (isset($kinds[BackupKind::Database->value], $kinds[BackupKind::Files->value])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws RestorePointNotVerified
     */
    public function assertVerified(Run $run): void
    {
        if (! $this->isVerified($run)) {
            throw RestorePointNotVerified::forRun($run->id);
        }
    }
}
