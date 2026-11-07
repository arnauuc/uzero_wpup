<?php

declare(strict_types=1);

namespace App\Console\Commands\Updates;

use App\Console\ChangesState;
use App\Console\Concerns\LaunchesRuns;
use App\Console\Concerns\ReadsPlanOptions;
use App\Console\Concerns\SelectsSites;
use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\Runs\Concurrency\MaintenanceWindowGate;
use App\Domain\Updates\DatabaseRollbackState;
use App\Domain\Updates\UpdateSummary;
use App\Enums\AuditResult;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\UpdateStrategy;
use App\Models\Run;
use App\Models\Site;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * `wpup update` (UZEROWPUP-60): the full update of one or more sites, one
 * queued run each, with the in-place strategy (staging comes in F7).
 *
 * - The runs wait in the single queue (ADR-0006: one update at a time, and per
 *   site, hosting account and server) and start only inside each site's
 *   maintenance window. An admin's `--now` (audited) skips the queue and the
 *   window, never the per-site and per-account locks.
 * - `--strategy` overrides the site's; a site whose own strategy is staging
 *   is not updated unless `--strategy=in-place` says so explicitly.
 * - `--dry-run` is the plan of the sites that would be updated (the same
 *   selection, strategy filter and --now check): it queues `plan` runs with the
 *   same options and changes nothing on the sites.
 * - `--allow-major`, `--skip-quarantine` and `--include` as in `plan` (admin, audited).
 */
final class UpdateCommand extends WpupCommand implements ChangesState
{
    protected $signature = 'update
        '.self::TARGETS.'
        {--strategy= : Estratègia d\'aquesta execució (in-place); per defecte, la del lloc}
        '.self::PLAN_OPTIONS.'
        {--now : Només admin: comença ara, fora de la cua global i de la finestra de manteniment (auditat)}
        '.self::DRY_RUN.'
        '.self::QUEUED.'
        '.self::JSON;

    protected $description = 'Actualitza els llocs: còpia verificada, actualització, comprovació i rollback si cal';

    use LaunchesRuns;
    use ReadsPlanOptions;
    use SelectsSites;

    protected function ability(): Ability
    {
        return Ability::RunOperations;
    }

    protected function perform(): int
    {
        $strategy = $this->strategy();
        if ($strategy === false) {
            return self::FAILURE;
        }

        $options = $this->planOptions();
        if ($options === null) {
            return self::FAILURE;
        }
        $now = $this->option('now') === true;
        if ($now && ! $this->requireAbility(Ability::SkipQueue, [], 'Només un admin pot fer servir --now.')) {
            return self::FAILURE;
        }

        $active = $this->activeSelectedSites("no s'actualitza");
        if ($active === null) {
            return self::FAILURE;
        }
        $sites = $this->inPlaceSites($active, $strategy);
        if ($sites === []) {
            return self::FAILURE;
        }
        if ($this->isDryRun()) {
            return $this->exitCodeWithSkipped($this->plan($sites));
        }

        $this->auditPlanOverrides($options, $sites);
        if ($now) {
            $this->audit->record($this->actor(), 'update.now', AuditResult::Success, 'command', (string) $this->getName(), [
                'sites' => array_map(static fn (Site $site): string => $site->name, $sites),
            ]);
        } else {
            $this->announceWindows($sites);
        }

        $flags = [...$options->toFlags(), ...($now ? ['now' => true] : [])];

        return $this->exitCodeWithSkipped($this->launchRuns(RunType::Update, $sites, $flags, UpdateStrategy::InPlace, $this->present(...)));
    }

    /**
     * @return UpdateStrategy|null|false the override, null for the site's own, false when refused
     */
    private function strategy(): UpdateStrategy|null|false
    {
        $value = $this->stringOption('strategy');

        return match ($value) {
            null => null,
            'in-place', 'in_place' => UpdateStrategy::InPlace,
            'staging', 'staging-first', 'staging_first' => $this->refuse("L'estratègia de staging encara no està disponible (arriba a F7, ADR-0005): de moment només --strategy=in-place."),
            default => $this->refuse("L'estratègia «{$value}» no existeix: fes servir --strategy=in-place."),
        };
    }

    private function refuse(string $message): false
    {
        $this->error($message);

        return false;
    }

    /**
     * The sites to update in place: a site whose own strategy is staging is only
     * updated in place when --strategy says so (it would otherwise expect a staging).
     *
     * @param  list<Site>  $sites
     * @return list<Site>
     */
    private function inPlaceSites(array $sites, ?UpdateStrategy $override): array
    {
        if ($override === UpdateStrategy::InPlace) {
            return $sites;
        }

        $kept = [];
        foreach ($sites as $site) {
            if ($site->strategy === UpdateStrategy::InPlace) {
                $kept[] = $site;

                continue;
            }
            $this->getOutput()->getErrorStyle()->warning("{$site->name}: la seva estratègia és staging, que arriba a F7; no s'actualitza. Fes servir --strategy=in-place per actualitzar-lo in-place.");
            $this->skippedSites++;
        }

        return $kept;
    }

    /**
     * Says, per site, when a run will start if its window is closed now.
     *
     * @param  list<Site>  $sites
     */
    private function announceWindows(array $sites): void
    {
        $gate = app(MaintenanceWindowGate::class);
        foreach ($sites as $site) {
            try {
                $opens = $gate->nextStart($site, CarbonImmutable::now());
                $label = $gate->windowOf($site)->label();
            } catch (InvalidArgumentException) {
                $this->getOutput()->getErrorStyle()->warning("{$site->name}: la finestra de manteniment no és vàlida; l'execució fallarà. Revisa-la amb `wpup site:edit`.");

                continue;
            }
            if ($opens !== null) {
                $this->getOutput()->getErrorStyle()->writeln("{$site->name}: fora de la finestra de manteniment ({$label}); l'actualització començarà a partir de les "
                    .$opens->format('H:i \d\e\l d/m/Y').'.');
            }
        }
    }

    /**
     * --dry-run: the plan of the sites that would be updated, with the same options; it changes nothing.
     *
     * @param  list<Site>  $sites
     */
    private function plan(array $sites): int
    {
        $arguments = ['sites' => array_map(static fn (Site $site): string => $site->name, $sites)];
        foreach (['allow-major', 'skip-quarantine', 'include', 'follow', 'wait', 'json'] as $option) {
            $arguments["--{$option}"] = $this->option($option);
        }
        if (! $this->wantsJson()) {
            $this->line("[dry-run] L'actualització faria primer aquest pla; després, per als llocs amb canvis: punt de restauració verificat, actualitzacions en aquest ordre, migracions i buidatge de memòria cau, i rollback si alguna cosa falla.");
        }

        return $this->call('plan', array_filter($arguments, static fn (mixed $v): bool => $v !== false && $v !== null && $v !== []));
    }

    /**
     * @param  list<Run>  $runs
     */
    private function present(array $runs): int
    {
        $summaries = array_map(UpdateSummary::of(...), $runs);
        if ($this->wantsJson()) {
            $this->emitJson(['runs' => array_map(static fn (UpdateSummary $s): array => $s->toArray(), $summaries)]);

            return 0;
        }

        foreach ($summaries as $summary) {
            $this->printSummary($summary);
        }

        return 0;
    }

    private function printSummary(UpdateSummary $summary): void
    {
        $run = $summary->run;
        $this->line('');
        $this->line('<options=bold>'.($run->site->name ?? '?')."</> — execució #{$run->id}: {$run->status->value}");

        if ($run->status === RunStatus::Succeeded) {
            $this->line($summary->nothingToDo()
                ? 'No hi havia res a aplicar ('.($summary->plan?->summary() ?? '').'): no s\'ha tocat el lloc.'
                : "Actualitzats: {$summary->updatedCount()}.");
        } else {
            $this->error((string) $run->failureReason());
        }
        foreach ($summary->interruptions as $interruption) {
            $this->warn($interruption->describe());
        }

        if ($summary->changes !== []) {
            $this->table(['Component', 'Abans', 'Després', 'Resultat'], array_map(static fn (array $c): array => [
                "{$c['type']}:{$c['slug']}", $c['before'] ?? '—', $c['after'] ?? '—', $c['result'],
            ], $summary->changes));
        }

        if ($summary->filesRestored !== null) {
            $this->line('Rollback: fitxers '.($summary->filesRestored ? 'restaurats' : 'NO restaurats').'; '
                .($summary->database ?? DatabaseRollbackState::NotReached)->label().'.');
        }
        if ($summary->databaseWarning !== null) {
            $this->warn($summary->databaseWarning);
        }
        if ($summary->forceCommand !== null) {
            $this->line("Per restaurar-la igualment (amb pèrdua de dades): {$summary->forceCommand}");
        }
    }
}
