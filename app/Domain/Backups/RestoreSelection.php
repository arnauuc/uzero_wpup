<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Enums\BackupKind;
use App\Enums\BackupTargetType;
use App\Models\Backup;
use App\Models\Site;

/**
 * Which registered copies a restore uses (UZEROWPUP-46). The command calls
 * select() before queueing the run; the run calls candidates() and rows()
 * again before doing anything, because a copy may be purged or the site may
 * change meanwhile.
 *
 * A copy is usable only if it is of the site, not deleted and verified at its
 * destination. Every file of a restore comes from one restore point (one key
 * directory). With --point, the candidates of each kind are its verified
 * copies, the site-local one first and then the external ones (oldest row
 * first); with --backup, exactly the rows given.
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

        $candidates = $point !== null ? $this->fromPoint($site, $point, $kinds) : array_map(static fn (Backup $row): array => [$row], $this->fromRows($site, $backupIds, $kinds));
        $directories = [];
        foreach ($candidates as $rows) {
            foreach ($rows as $row) {
                $directories[dirname($row->path)] = true;
            }
        }
        if (count($directories) !== 1) {
            throw new RestoreRefused('Les còpies indicades són de punts de restauració diferents: totes han de ser del mateix.');
        }

        return new RestoreRequest(
            (string) array_key_first($directories),
            array_map(static fn (array $rows): array => array_map(static fn (Backup $row): int => $row->id, $rows), $candidates),
            $acceptDataLoss,
        );
    }

    /**
     * The candidates of $request that are still usable now, by kind in restore order.
     *
     * @return array<string, non-empty-list<Backup>>
     *
     * @throws RestoreRefused when a kind has none left
     */
    public function candidates(Site $site, RestoreRequest $request): array
    {
        $usable = [];
        foreach ($request->kinds() as $kind) {
            $reasons = [];
            foreach ($request->candidates[$kind->value] as $id) {
                try {
                    $usable[$kind->value][] = $this->usable($site, $request, $kind, $id);
                } catch (RestoreRefused $e) {
                    $reasons[] = $e->getMessage();
                }
            }
            if (! isset($usable[$kind->value])) {
                throw new RestoreRefused(implode(' ', $reasons));
            }
        }

        return $usable;
    }

    /**
     * The rows RestoreCheckStep chose ($chosen: kind => id, each one of the
     * request's candidates), checked again now, by kind in restore order.
     *
     * @param  array<string, int>  $chosen
     * @return array<string, Backup>
     *
     * @throws RestoreRefused
     */
    public function rows(Site $site, RestoreRequest $request, array $chosen): array
    {
        $rows = [];
        foreach ($request->kinds() as $kind) {
            $id = $chosen[$kind->value] ?? null;
            if ($id === null || ! in_array($id, $request->candidates[$kind->value], true)) {
                throw new RestoreRefused('No s\'ha triat cap còpia vàlida '.self::kindLabel($kind).'.');
            }
            $rows[$kind->value] = $this->usable($site, $request, $kind, $id);
        }

        return $rows;
    }

    /**
     * The usable copies of $kind of a point right now (UZEROWPUP-57): of the
     * site, live and verified, the site-local one first.
     *
     * @return non-empty-list<Backup>
     *
     * @throws RestoreRefused
     */
    public function ofPoint(Site $site, string $point, BackupKind $kind): array
    {
        return $this->fromPoint($site, $point, [$kind])[$kind->value];
    }

    public static function kindLabel(BackupKind $kind): string
    {
        return $kind === BackupKind::Database ? 'de la base de dades' : 'dels fitxers';
    }

    /**
     * @throws RestoreRefused
     */
    private function usable(Site $site, RestoreRequest $request, BackupKind $kind, int $id): Backup
    {
        $row = $this->fromRows($site, [$id], [$kind])[$kind->value];
        if (dirname($row->path) !== $request->directory) {
            throw new RestoreRefused("La còpia #{$row->id} no és del punt de restauració «{$request->directory}».");
        }

        return $row;
    }

    /**
     * @param  list<BackupKind>  $kinds
     * @return array<string, non-empty-list<Backup>>
     */
    private function fromPoint(Site $site, string $point, array $kinds): array
    {
        $directory = $this->directory($site, $point);
        $rows = Backup::query()->with('backupTarget')->where('site_id', $site->id)->whereNull('deleted_at')
            ->where('path', 'like', $directory.'/%')->orderBy('id')->get()
            ->filter(static fn (Backup $row): bool => dirname($row->path) === $directory);

        $candidates = [];
        foreach ($kinds as $kind) {
            $ofKind = $rows->filter(static fn (Backup $row): bool => $row->kind === $kind);
            if ($ofKind->isEmpty()) {
                throw new RestoreRefused("El punt de restauració «{$directory}» del lloc {$site->name} no té cap còpia ".self::kindLabel($kind).' viva.');
            }
            $verified = $ofKind->filter(static fn (Backup $row): bool => $row->verified_at !== null)
                ->sortBy(static fn (Backup $row): array => [$row->backupTarget?->type === BackupTargetType::SiteLocal ? 0 : 1, $row->id]);
            $list = array_values($verified->all());
            if ($list === []) {
                throw new RestoreRefused('La còpia '.self::kindLabel($kind)." del punt «{$directory}» no està verificada a cap destinació: no es pot restaurar.");
            }
            $candidates[$kind->value] = $list;
        }

        return $candidates;
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
}
