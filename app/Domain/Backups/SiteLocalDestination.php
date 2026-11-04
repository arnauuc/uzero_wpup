<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Enums\BackupTargetType;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Site;

/**
 * Every site keeps its restore points on its own server (ADR-0009): one shared
 * backup_targets row of type site_local stands for that destination, and every
 * site is linked to it (site_backup_targets), so BackupTargetRegistry::for()
 * resolves it like any other destination. The migration that introduced it
 * linked the existing sites; SiteAdministration::add() links new ones.
 */
final class SiteLocalDestination
{
    /**
     * The shared row, created if missing.
     */
    public function record(): BackupTargetRecord
    {
        return BackupTargetRecord::query()->where('type', BackupTargetType::SiteLocal)->orderBy('id')->first()
            ?? BackupTargetRecord::query()->create([
                'name' => config()->string('wpup.backups.site_local.name'),
                'type' => BackupTargetType::SiteLocal,
                'config' => [],
                'retention' => [],
                'active' => true,
            ]);
    }

    public function attach(Site $site): void
    {
        $site->backupTargets()->syncWithoutDetaching([$this->record()->id]);
    }
}
