<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Audit\AuditActor;
use App\Enums\BackupTargetType;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Site;
use Closure;

/**
 * Resolves a configured destination (backup_targets row) to its BackupTarget
 * for a site. Each destination type registers a factory once, in
 * AppServiceProvider; nothing else needs to change to add one.
 */
final class BackupTargetRegistry
{
    /**
     * @var array<string, Closure(BackupTargetRecord, Site, AuditActor): BackupTarget>
     */
    private array $factories = [];

    /**
     * @param  Closure(BackupTargetRecord, Site, AuditActor): BackupTarget  $factory  credentials it reveals are audited as the actor
     */
    public function register(BackupTargetType $type, Closure $factory): void
    {
        $this->factories[$type->value] = $factory;
    }

    public function has(BackupTargetType $type): bool
    {
        return isset($this->factories[$type->value]);
    }

    /**
     * @throws BackupTargetException when the type is not registered, the destination is inactive or not one of the site's
     */
    public function for(BackupTargetRecord $record, Site $site, AuditActor $actor): BackupTarget
    {
        // Only the destinations configured for the site (site_backup_targets).
        if (! $site->backupTargets()->whereKey($record->id)->exists()) {
            throw new BackupTargetException("La destinació de còpia «{$record->name}» no és del lloc {$site->name}.");
        }
        if (! $record->active) {
            throw new BackupTargetException("La destinació de còpia «{$record->name}» està desactivada.");
        }

        $factory = $this->factories[$record->type->value] ?? throw BackupTargetException::unregistered($record->type->value);

        return $factory($record, $site, $actor);
    }

    /**
     * Only to delete copies that already exist there (retention): unlike
     * for(), it also opens a destination that was deactivated or is no longer
     * linked to the site, so that its restore points do not stay forever.
     * Never use it to write or read.
     *
     * @throws BackupTargetException when the type is not registered
     */
    public function forDeletion(BackupTargetRecord $record, Site $site, AuditActor $actor): BackupTarget
    {
        $factory = $this->factories[$record->type->value] ?? throw BackupTargetException::unregistered($record->type->value);

        return $factory($record, $site, $actor);
    }
}
