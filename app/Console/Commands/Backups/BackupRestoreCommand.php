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
use App\Domain\Identity\Ability;
use App\Enums\BackupKind;
use App\Enums\RunType;
use App\Models\Backup;
use App\Models\Site;

/**
 * Restores a site from one of its registered copies (UZEROWPUP-46), as a
 * queued run: fresh inventory, the copies checked at their destination, a
 * verified restore point of the current state, then the restore. Destructive:
 * the operator types the site's name to confirm, or passes --force (scripts).
 * A shop's database also needs --accept-data-loss (AGENTS.md rule 4).
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
        {--accept-data-loss : Accepta perdre les comandes i els usuaris entrats des de la còpia (botigues)}
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
            $rows = $selection->rows($site, $request);
        } catch (RestoreRefused $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->wantsJson()) {
            $this->printPlan($site, $request, $rows);
        }

        $force = $this->option('force') === true;
        if (! $this->isDryRun() && ! $force) {
            if ($this->wantsJson() || ! $this->input->isInteractive()) {
                $this->error('Sense terminal interactiu (o amb --json), cal --dry-run o --force: no es pot demanar confirmació.');

                return self::FAILURE;
            }
            $typed = $this->ask("Això sobreescriu {$this->what($request)} del lloc {$site->name} i no es pot desfer des d'aquí. Escriu el nom del lloc per confirmar-ho");
            if ($typed !== $site->name) {
                $this->warn("No s'ha restaurat res.");

                return self::FAILURE;
            }
        }

        return $this->launchRuns(RunType::Restore, [$site], ['dry_run' => $this->isDryRun()] + $request->flags());
    }

    /**
     * @param  array<string, Backup>  $rows
     */
    private function printPlan(Site $site, RestoreRequest $request, array $rows): void
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
        $this->line("Abans de restaurar, l'execució comprova el SHA-256 de cada còpia i fa un punt de restauració verificat de l'estat actual; si no el pot fer, no restaura res.");
        if ($request->restoresDatabase()) {
            $since = $rows[BackupKind::Database->value]->created_at->format(BackupListing::dateFormat());
            $this->warn("La base de dades es reemplaça sencera: es perd tot el que s'hi hagi escrit des de la còpia ({$since}), també les comandes, els clients i els usuaris d'una botiga.");
            $this->warn($request->acceptDataLoss
                ? 'Ho acceptes amb --accept-data-loss.'
                : 'Si el lloc és una botiga (WooCommerce), l\'execució s\'aturarà abans de tocar res: cal --accept-data-loss.');
        }
    }

    private function what(RestoreRequest $request): string
    {
        return implode(' i ', array_map(static fn (BackupKind $k): string => $k === BackupKind::Database ? 'la base de dades' : 'els fitxers', $request->kinds()));
    }
}
