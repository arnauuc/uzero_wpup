<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Enums\BackupKind;
use App\Enums\BackupTargetType;
use App\Models\Backup;
use App\Models\Site;

/**
 * Which registered copies a restore uses (UZEROWPUP-46). The command calls
 * select() before queueing the run; the run calls rows() again before doing
 * anything, because a copy may be purged or the site may change meanwhile.
 *
 * A copy is usable only if it is of the site, not deleted and verified at its
 * destination. Every file of a restore comes from one restore point (one key
 * directory). Without explicit rows, each kind comes from the site-local
 * destination when it holds a verified copy, otherwise from a verified
 * external one.
 */
final class RestoreSelection
{
    /**
     * @param  string|null  $point  the key directory ("site-3/run12"), its last segment ("run12", "manual-…") or a run id ("12")
     * @param  list<int>  $backupIds  explicit rows (`--backup`), instead of $point
     * @param  list<BackupKind>  $kinds
     *
     * @throws RestoreRefused
     */
    public function select(Site $site, ?string $point, array $backupIds, array $kinds, bool $acceptDataLoss): RestoreRequest
    {
        if ($kinds === []) {
            throw new RestoreRefused('Indica què vols restaurar: --files, --db o tots dos.');
        }
        if (($point === null) === ($backupIds === [])) {
            throw new RestoreRefused('Indica el punt de restauració amb --point o les còpies amb --backup (només una de les dues).');
        }

        $chosen = $point !== null ? $this->fromPoint($site, $point, $kinds) : $this->fromRows($site, $backupIds, $kinds);
        $directories = array_unique(array_map(static fn (Backup $row): string => dirname($row->path), $chosen));
        if (count($directories) !== 1) {
            throw new RestoreRefused('Les còpies indicades són de punts de restauració diferents: totes han de ser del mateix.');
        }

        return new RestoreRequest((string) reset($directories), array_map(static fn (Backup $row): int => $row->id, $chosen), $acceptDataLoss);
    }

    /**
     * The rows of $request, checked again now, by kind in restore order.
     *
     * @return array<string, Backup>
     *
     * @throws RestoreRefused
     */
    public function rows(Site $site, RestoreRequest $request): array
    {
        $rows = $this->fromRows($site, array_values($request->backupIds), $request->kinds());
        foreach ($rows as $row) {
            if (dirname($row->path) !== $request->directory) {
                throw new RestoreRefused("La còpia #{$row->id} no és del punt de restauració «{$request->directory}».");
            }
        }

        $ordered = [];
        foreach ($request->kinds() as $kind) {
            $ordered[$kind->value] = $rows[$kind->value];
        }

        return $ordered;
    }

    /**
     * @param  list<BackupKind>  $kinds
     * @return array<string, Backup>
     */
    private function fromPoint(Site $site, string $point, array $kinds): array
    {
        $directory = $this->directory($site, $point);
        $rows = Backup::query()->with('backupTarget')->where('site_id', $site->id)->whereNull('deleted_at')
            ->where('path', 'like', $directory.'/%')
            ->orderBy('id')->get()
            ->filter(static fn (Backup $row): bool => dirname($row->path) === $directory);

        $chosen = [];
        foreach ($kinds as $kind) {
            $ofKind = $rows->filter(static fn (Backup $row): bool => $row->kind === $kind);
            if ($ofKind->isEmpty()) {
                throw new RestoreRefused("El punt de restauració «{$directory}» del lloc {$site->name} no té cap còpia ".self::kindLabel($kind).' viva.');
            }
            $verified = $ofKind->filter(static fn (Backup $row): bool => $row->verified_at !== null);
            $row = $verified->first(static fn (Backup $row): bool => $row->backupTarget?->type === BackupTargetType::SiteLocal) ?? $verified->first();
            $chosen[$kind->value] = $row ?? throw new RestoreRefused('La còpia '.self::kindLabel($kind)." del punt «{$directory}» no està verificada a cap destinació: no es pot restaurar.");
        }

        return $chosen;
    }

    /**
     * @param  list<int>  $ids
     * @param  list<BackupKind>  $kinds
     * @return array<string, Backup>
     */
    private function fromRows(Site $site, array $ids, array $kinds): array
    {
        $chosen = [];
        foreach (array_unique($ids) as $id) {
            $row = Backup::query()->with('backupTarget')->find($id) ?? throw new RestoreRefused("La còpia #{$id} no existeix.");
            if ($row->site_id !== $site->id) {
                throw new RestoreRefused("La còpia #{$id} és d'un altre lloc, no de {$site->name}.");
            }
            if ($row->deleted_at !== null) {
                throw new RestoreRefused("La còpia #{$id} s'ha esborrat: no es pot restaurar.");
            }
            if ($row->verified_at === null) {
                throw new RestoreRefused("La còpia #{$id} no està verificada a la seva destinació: no es pot restaurar.");
            }
            if (! in_array($row->kind, $kinds, true)) {
                throw new RestoreRefused("La còpia #{$id} és ".self::kindLabel($row->kind).', i no l\'has demanat ('.($row->kind === BackupKind::Database ? '--db' : '--files').').');
            }
            if (isset($chosen[$row->kind->value])) {
                throw new RestoreRefused('Has indicat dues còpies '.self::kindLabel($row->kind).': només se\'n pot restaurar una.');
            }
            $chosen[$row->kind->value] = $row;
        }
        foreach ($kinds as $kind) {
            if (! isset($chosen[$kind->value])) {
                throw new RestoreRefused('Falta la còpia '.self::kindLabel($kind).' (--backup=<id>).');
            }
        }

        return $chosen;
    }

    /**
     * Keys are "site-{id}/…": a point of another site is never found under this one.
     */
    private function directory(Site $site, string $point): string
    {
        $prefix = "site-{$site->id}/";
        $directory = match (true) {
            ctype_digit($point) => "{$prefix}run{$point}",
            ! str_contains($point, '/') => $prefix.$point,
            default => $point,
        };
        if (! str_starts_with($directory, $prefix) || substr_count($directory, '/') !== 1 || ! BackupKey::isValid($directory)) {
            throw new RestoreRefused("«{$point}» no és un punt de restauració del lloc {$site->name}.");
        }

        return $directory;
    }

    public static function kindLabel(BackupKind $kind): string
    {
        return $kind === BackupKind::Database ? 'de la base de dades' : 'dels fitxers';
    }
}
