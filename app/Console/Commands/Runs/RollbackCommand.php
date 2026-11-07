<?php

declare(strict_types=1);

namespace App\Console\Commands\Runs;

use App\Console\ChangesState;
use App\Console\Concerns\LaunchesRuns;
use App\Console\WpupCommand;
use App\Domain\Backups\BackupListing;
use App\Domain\Backups\ShopGuard;
use App\Domain\Identity\Ability;
use App\Domain\Rollback\DatabaseRollbackStep;
use App\Domain\Rollback\ManualRollback;
use App\Domain\Rollback\RollbackRefused;
use App\Domain\Rollback\RollbackSource;
use App\Enums\AuditResult;
use App\Enums\RunType;
use App\Models\Backup;
use App\Models\Run;
use App\Models\RunStep;

/**
 * Rolls back an update run by hand (`wpup rollback <run>`, UZEROWPUP-61), as
 * a queued run of type rollback linked to it (parent_run_id): fresh
 * inventory, the update and the SHA-256 of its copies checked before
 * writing anything, maintenance mode, a verified restore point of the
 * current state, the files, then the database only if nothing was written to
 * the orders or users since the copy (AGENTS.md rule 4: never forced here),
 * and the maintenance mode as it was.
 *
 * Refused before queueing (and again by the run): not an update, unfinished,
 * already rolled back, nothing applied, a later update still applied, or a
 * copy expired or not verified. Destructive: the operator types the site's
 * name, or passes --force (scripts), as `backup:restore`. No new orders can
 * be lost, so --force is enough.
 */
final class RollbackCommand extends WpupCommand implements ChangesState
{
    use LaunchesRuns;

    protected $signature = 'rollback
        {run : Id de l\'execució d\'actualització que es vol desfer}
        {--force : No demana confirmació (scripts)}
        '.self::DRY_RUN.'
        '.self::QUEUED.'
        '.self::JSON;

    protected $description = 'Desfà una actualització anterior: fitxers i, si no hi ha comandes ni usuaris nous, base de dades';

    protected function ability(): Ability
    {
        return Ability::RunOperations;
    }

    protected function perform(): int
    {
        $id = $this->stringArgument('run');
        $source = ctype_digit($id) ? Run::query()->with('site')->find((int) $id) : null;
        if ($source === null) {
            $this->error("No hi ha cap execució «{$id}».");

            return self::FAILURE;
        }

        try {
            if ($source->type !== RunType::Update) {
                // Never the parent of a rollback run: the operator names the update.
                throw new RollbackRefused("L'execució #{$source->id} no és una actualització ({$source->type->value}): no hi ha res a desfer.");
            }
            $source = RollbackSource::of($source);
            $site = $source->site ?? throw new RollbackRefused("L'execució #{$source->id} no té cap lloc.");
            if (! $site->active) {
                throw new RollbackRefused("{$site->name}: inactiu, no es fa el rollback.");
            }
            $rollback = app(ManualRollback::class);
            $rollback->assertRevertible($source);
            $copies = $rollback->copies($site, $source);
        } catch (RollbackRefused $e) {
            $this->audit->record($this->actor(), 'rollback.refused', AuditResult::Failure, 'run', $source->id, ['reason' => $e->getMessage()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->wantsJson()) {
            $this->printPlan($source, $copies);
        }

        $confirmation = 'dry-run';
        if (! $this->isDryRun()) {
            $confirmation = 'force';
            if ($this->option('force') !== true) {
                if ($this->wantsJson() || ! $this->input->isInteractive()) {
                    $this->error('Sense terminal interactiu (o amb --json), cal --dry-run o --force: no es pot demanar confirmació.');

                    return self::FAILURE;
                }
                $typed = $this->ask("Això torna el codi del lloc {$site->name} a l'estat d'abans de l'actualització #{$source->id}. Escriu el nom del lloc per confirmar-ho");
                if ($typed !== $site->name) {
                    $this->warn("No s'ha desfet res.");

                    return self::FAILURE;
                }
                $confirmation = 'typed';
            }
        }

        $this->audit->record($this->actor(), 'rollback.queued', AuditResult::Success, 'run', $source->id, [
            'site' => $site->name, 'dry_run' => $this->isDryRun(), 'confirmation' => $confirmation,
        ]);

        return $this->launchRuns(RunType::Rollback, [$site], ['dry_run' => $this->isDryRun()], null, function (array $runs): ?int {
            $this->present($runs);

            return null;
        }, $source);
    }

    /**
     * @param  array<string, non-empty-list<Backup>>  $copies
     */
    private function printPlan(Run $source, array $copies): void
    {
        $site = $source->site;
        $rows = array_merge(...array_values($copies));
        $this->line(($this->isDryRun() ? '[dry-run] ' : '')."<options=bold>{$site?->name}</> ({$site?->url}): es desfarà l'actualització #{$source->id} ({$source->status->value}, "
            .($source->finished_at?->format(BackupListing::dateFormat()) ?? '?').') des del seu punt de restauració.');
        $this->table(['#', 'Tipus', 'Destinació', 'SHA-256', 'Creada', 'Caduca'], array_map(static fn (Backup $b): array => [
            $b->id,
            $b->kind->value,
            (string) $b->backupTarget?->name,
            substr($b->sha256, 0, 12),
            $b->created_at->format(BackupListing::dateFormat()),
            $b->expires_at?->format(BackupListing::dateFormat()) ?? '—',
        ], $rows));
        $this->line("Abans de tocar res, l'execució comprova el SHA-256 de les còpies a la destinació, activa el mode manteniment i fa un punt de restauració verificat de l'estat actual; si no el pot fer, no desfà res.");
        $this->line('Es restauren sempre els fitxers. La base de dades només es restaura si no s\'ha escrit res a les comandes ni als usuaris des de la còpia; si no, no es toca i se n\'avisa.');
        if ($site !== null && ShopGuard::isShop(ShopGuard::latest($site))) {
            $this->warn('El lloc és (o pot ser) una botiga: si hi ha comandes noves, només es restauraran els fitxers.');
        }
    }

    /**
     * The default table, plus what the database step says when it left the database untouched.
     *
     * @param  list<Run>  $runs
     */
    private function present(array $runs): void
    {
        if ($this->wantsJson()) {
            $this->emitJson(['runs' => array_map(fn (Run $run): array => [
                'id' => $run->id, 'site' => $run->site?->name, 'type' => $run->type->value, 'status' => $run->status->value,
                'source_run_id' => $run->parent_run_id, 'database' => $this->databaseStep($run)?->result, 'result' => $run->result_summary,
            ], $runs)]);

            return;
        }

        $this->table(['Execució', 'Lloc', 'Estat', 'Motiu'], array_map(static fn (Run $run): array => [
            "#{$run->id}", $run->targetLabel(), $run->status->value, $run->failureReason() ?? '',
        ], $runs));
        foreach ($runs as $run) {
            $result = $this->databaseStep($run)->result ?? [];
            if (($result['restored'] ?? null) === false) {
                $this->warn(is_string($result['warning'] ?? null) ? $result['warning'] : 'La base de dades no s\'ha restaurat.');
                if (is_string($result['force_command'] ?? null)) {
                    $this->line("Per restaurar-la igualment (es perden les dades noves): {$result['force_command']}");
                }
            }
        }
    }

    private function databaseStep(Run $run): ?RunStep
    {
        /** @var RunStep|null */
        return $run->steps()->where('name', DatabaseRollbackStep::NAME)->orderByDesc('id')->first();
    }
}
