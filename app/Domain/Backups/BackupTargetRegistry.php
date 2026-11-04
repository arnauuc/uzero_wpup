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
     * @throws BackupTargetException when the type is not registered or the destination is inactive
     */
    public function for(BackupTargetRecord $record, Site $site, AuditActor $actor): BackupTarget
    {
        if (! $record->active) {
            throw new BackupTargetException("La destinació de còpia «{$record->name}» està desactivada.");
        }

        $factory = $this->factories[$record->type->value] ?? throw BackupTargetException::unregistered($record->type->value);

        return $factory($record, $site, $actor);
    }
}
