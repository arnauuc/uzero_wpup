<?php

declare(strict_types=1);

namespace App\Console\Commands\Backups;

use App\Console\ChangesState;
use App\Console\Concerns\LaunchesRuns;
use App\Console\WpupCommand;
use App\Domain\Backups\BackupListing;
use App\Domain\Backups\ByteSize;
use App\Domain\Backups\RestoreRefused;
use App\Domain\Backups\RestoreRequest;
use App\Domain\Backups\RestoreSelection;
use App\Domain\Backups\ShopGuard;
use App\Domain\Identity\Ability;
use App\Enums\BackupKind;
use App\Enums\RunType;
use App\Models\Backup;
use App\Models\Site;

/**
 * Restores a site from one of its registered copies (UZEROWPUP-46), as a
 * queued run: fresh inventory, the copies checked at their destination, a
 * maintenance mode, a verified restore point of the current state, then the
 * restore. Destructive: the operator types the site's name to confirm, or
 * passes --force (scripts). Two cases always need a person at the terminal,
 * --force or not: a shop's database (AGENTS.md rule 4: --accept-data-loss
 * and the typed name, while UZEROWPUP-58 cannot tell what would be lost; a
 * site with no known inventory counts as a shop) and --without-pre-backup.
 */
final class BackupRestoreCommand extends WpupCommand implements ChangesState
{
    use LaunchesRuns;

    protected $signature = 'backup:restore
        {site : Nom del lloc}
        {--point= : Punt de restauració: el directori (site-3/run12), el seu nom (run12, manual-…) o l\'id de l\'execució}
        {--backup=* : Ids de les còpies a restaurar (backup:list), en lloc de --point}
        {--files : Restaura els fitxers}
        {--db : Restaura la base de dades}
        {--accept-data-loss : Accepta perdre les comandes i els usuaris entrats des de la còpia (botigues; cal confirmar-ho de manera interactiva)}
        {--without-pre-backup : Restaura encara que no es pugui fer el punt de l\'estat actual (lloc trencat; només interactiu)}
        {--force : No demana confirmació (scripts)}
        '.self::DRY_RUN.'
        '.self::QUEUED.'
        '.self::JSON;

    protected $description = 'Restaura un lloc (fitxers, base de dades o tots dos) des d\'una còpia registrada';

    protected function ability(): Ability
    {
        return Ability::RunOperations;
    }

    protected function perform(): int
    {
        $name = $this->stringArgument('site');
        $site = Site::query()->where('name', $name)->first();
        if ($site === null || ! $site->active) {
            $this->error($site === null ? "No hi ha cap lloc «{$name}»." : "{$name}: inactiu, no es restaura.");

            return self::FAILURE;
        }

        $kinds = array_values(array_filter([
            $this->option('files') === true ? BackupKind::Files : null,
            $this->option('db') === true ? BackupKind::Database : null,
        ]));
        $ids = $this->option('backup');
        $ids = is_array($ids) ? array_values(array_filter($ids, is_string(...))) : [];
        if (array_filter($ids, static fn (string $id): bool => ! ctype_digit($id)) !== []) {
            $this->error('--backup ha de ser l\'id numèric d\'una còpia.');

            return self::FAILURE;
        }

        try {
            $selection = app(RestoreSelection::class);
            $request = $selection->select($site, $this->stringOption('point'), array_map(intval(...), $ids), $kinds, $this->option('accept-data-loss') === true);
            $rows = array_map(static fn (array $candidates): Backup => $candidates[0], $selection->candidates($site, $request));
        } catch (RestoreRefused $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $shopDatabase = $request->restoresDatabase() && ShopGuard::isShop(ShopGuard::latest($site));
        $withoutPreBackup = $this->option('without-pre-backup') === true;
        if ($shopDatabase && ! $request->acceptDataLoss && ! $this->isDryRun()) {
            $this->error(ShopGuard::message($rows[BackupKind::Database->value]->created_at->format(BackupListing::dateFormat())).' Cal --accept-data-loss.');

            return self::FAILURE;
        }

        if (! $this->wantsJson()) {
            $this->printPlan($site, $request, $rows, $shopDatabase, $withoutPreBackup);
        }

        $confirmed = false;
        if (! $this->isDryRun()) {
            // Never skipped by --force: a shop's data, or no way back.
            $human = $shopDatabase || $withoutPreBackup;
            if ($human || $this->option('force') !== true) {
                if ($this->wantsJson() || ! $this->input->isInteractive()) {
                    $this->error($human
                        ? 'Aquesta restauració '.($shopDatabase ? 'esborra dades d\'una botiga' : 'es fa sense punt de l\'estat actual').': cal confirmar-la en un terminal interactiu, també amb --force.'
                        : 'Sense terminal interactiu (o amb --json), cal --dry-run o --force: no es pot demanar confirmació.');

                    return self::FAILURE;
                }
                $typed = $this->ask("Això sobreescriu {$this->what($request)} del lloc {$site->name}"
                    .($shopDatabase ? ', amb les comandes, els clients i els usuaris entrats des de la còpia' : '')
                    .($withoutPreBackup ? ", i si no es pot fer el punt de l'estat actual no hi haurà manera de tornar enrere" : '')
                    .'. Escriu el nom del lloc per confirmar-ho');
                if ($typed !== $site->name) {
                    $this->warn("No s'ha restaurat res.");

                    return self::FAILURE;
                }
                $confirmed = true;
            }
        }

        $request = $request->with($shopDatabase && $confirmed, $withoutPreBackup && $confirmed);

        return $this->launchRuns(RunType::Restore, [$site], ['dry_run' => $this->isDryRun()] + $request->flags());
    }

    /**
     * @param  array<string, Backup>  $rows
     */
    private function printPlan(Site $site, RestoreRequest $request, array $rows, bool $shopDatabase, bool $withoutPreBackup): void
    {
        $this->line(($this->isDryRun() ? '[dry-run] ' : '')."<options=bold>{$site->name}</> ({$site->url}): es restauraran {$this->what($request)} des del punt «{$request->directory}».");
        $this->table(['#', 'Tipus', 'Destinació', 'Clau', 'Mida', 'SHA-256', 'Creada'], array_map(static fn (Backup $b): array => [
            $b->id,
            $b->kind->value,
            (string) $b->backupTarget?->name,
            $b->path,
            ByteSize::human($b->size_bytes),
            substr($b->sha256, 0, 12),
            $b->created_at->format(BackupListing::dateFormat()),
        ], array_values($rows)));
        $this->line("Abans de restaurar, l'execució comprova el SHA-256 de cada còpia, activa el mode manteniment i fa un punt de restauració verificat de l'estat actual"
            .($withoutPreBackup ? '; si no el pot fer, restaura igualment (--without-pre-backup).' : '; si no el pot fer, no restaura res.'));
        if ($request->restoresDatabase()) {
            $since = $rows[BackupKind::Database->value]->created_at->format(BackupListing::dateFormat());
            $this->warn("La base de dades es reemplaça sencera: es perd tot el que s'hi hagi escrit des de la còpia ({$since}), també les comandes, els clients i els usuaris d'una botiga.");
            if ($shopDatabase) {
                $this->warn('El lloc és (o pot ser) una botiga: ho acceptes amb --accept-data-loss, i cal confirmar-ho escrivint el nom del lloc.');
            }
        }
    }

    private function what(RestoreRequest $request): string
    {
        return implode(' i ', array_map(static fn (BackupKind $k): string => $k === BackupKind::Database ? 'la base de dades' : 'els fitxers', $request->kinds()));
    }
}
