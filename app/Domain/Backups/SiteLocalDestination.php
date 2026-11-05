<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Enums\BackupTargetType;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Site;
use Closure;

/**
 * Every site keeps its restore points on its own server (ADR-0009): one shared
 * backup_targets row of type site_local stands for that destination, and every
 * site is linked to it (site_backup_targets), so BackupTargetRegistry::for()
 * resolves it like any other destination. The migration that introduced it
 * linked the existing sites; SiteAdministration::add() links new ones.
 *
 * The row is found by type. It is created with the configured name
 * (wpup.backups.site_local.name) or, if a destination of another type already
 * has that name, with "{name}-site-local"; if both are taken, creating it fails
 * with an explanation (an admin must rename one).
 */
final class SiteLocalDestination
{
    public const string ALTERNATIVE_SUFFIX = '-site-local';

    /**
     * The shared row, created if missing.
     *
     * @throws BackupTargetException when no free name is left
     */
    public function record(): BackupTargetRecord
    {
        return BackupTargetRecord::query()->where('type', BackupTargetType::SiteLocal)->orderBy('id')->first()
            ?? BackupTargetRecord::query()->create([
                'name' => self::nameFor(
                    config()->string('wpup.backups.site_local.name'),
                    static fn (string $name): bool => BackupTargetRecord::query()->where('name', $name)->exists(),
                ),
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

    /**
     * The name for a new site_local row (shared with its migration).
     *
     * @param  Closure(string): bool  $taken  whether a destination already has that name
     *
     * @throws BackupTargetException
     */
    public static function nameFor(string $configured, Closure $taken): string
    {
        if (! $taken($configured)) {
            return $configured;
        }

        $alternative = $configured.self::ALTERNATIVE_SUFFIX;

        return $taken($alternative)
            ? throw new BackupTargetException("No es pot crear la destinació local dels llocs: els noms «{$configured}» i «{$alternative}» ja són d'altres destinacions. Canvia'n un o wpup.backups.site_local.name.")
            : $alternative;
    }
}
