<?php

declare(strict_types=1);

namespace App\Console\Commands\Planning;

use App\Console\Concerns\LaunchesRuns;
use App\Console\Concerns\PrintsRunHeader;
use App\Console\Concerns\SelectsSites;
use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\Planning\PlannedChange;
use App\Domain\Planning\PlanReport;
use App\Domain\Planning\PlanRow;
use App\Enums\RunType;
use App\Enums\SkipReason;
use App\Models\Run;

/**
 * Plan of the updates of one or more sites as queued runs (UZEROWPUP-36). It
 * changes neither the content of the site nor its block state (no probe, no block
 * change), so it is not a ChangesState command and has no --dry-run. It does
 * write to the tool (run, steps, snapshot, audit rows), and the driver may install
 * the tool's WP-CLI in its working directory on the site's account.
 */
final class PlanCommand extends WpupCommand
{
    protected $signature = 'plan
        '.self::TARGETS.'
        '.self::QUEUED.'
        '.self::JSON;

    protected $description = "Mostra què s'actualitzaria i què s'ometria, i per què, sense tocar els llocs";

    use LaunchesRuns;
    use PrintsRunHeader;
    use SelectsSites;

    protected function ability(): Ability
    {
        return Ability::RunOperations;
    }

    protected function perform(): int
    {
        $active = $this->activeSelectedSites('no es planifica');
        if ($active === null) {
            return self::FAILURE;
        }

        $present = function (array $runs): int {
            /** @var list<Run> $runs */
            $report = app(PlanReport::class);
            $rows = $report->rows($runs);

            if ($this->wantsJson()) {
                $this->emitJson($report->document($rows));
            } else {
                array_map($this->printRow(...), $rows);
            }

            // A run that left no usable plan is not a success, even if the run itself succeeded.
            return array_filter($rows, static fn (PlanRow $row): bool => ! $row->hasPlan()) === [] ? 0 : self::FAILURE;
        };

        return $this->exitCodeWithSkipped($this->launchRuns(RunType::Plan, $active, [], null, $present));
    }

    /**
     * Why it is skipped, or the note on an update.
     */
    private static function reason(PlannedChange $change): string
    {
        $label = $change->reason?->label();

        return match (true) {
            $label === null => $change->detail ?? '',
            $change->detail === null => $label,
            default => "{$label} — {$change->detail}",
        };
    }

    private function printRow(PlanRow $row): void
    {
        $this->printRunHeader($row->site, $row->url, $row->runId, $row->runStatus, $row->failure, 'El pla');

        $plan = $row->plan;
        if ($plan === null) {
            return;
        }

        $this->line('PHP '.$plan->phpVersion.' ('.$plan->phpSource->value.')'.($plan->phpVerified ? '' : ': no verificat pel web, és el del CLI'));
        $this->line('Bloquejat: '.($plan->blockedReason === null ? 'no' : "sí: {$plan->blockedReason}"));
        $this->line('Cal staging primer: '.($plan->requiresStaging() ? "sí: els canvis que l'exigeixen s'ometen fins a F7" : 'no'));

        if ($plan->changes === []) {
            $this->line('No hi ha cap actualització pendent.');

            return;
        }

        $this->table(['Component', 'Actual', 'Objectiu', 'Acció', 'Motiu'], array_map(static fn (PlannedChange $c): array => [
            "{$c->type->value}:{$c->slug}".($c->major ? ' (major)' : ''),
            $c->currentVersion,
            $c->targetVersion,
            $c->action->label(),
            self::reason($c),
        ], $plan->changes));
        foreach ($plan->changes as $c) {
            foreach ($c->warnings as $warning) {
                $this->line("Avís {$c->type->value}:{$c->slug}: {$warning}");
            }
        }
        $this->line($plan->summary().'.');

        $quarantined = array_filter($plan->changes, static fn (PlannedChange $c): bool => $c->reason === SkipReason::Quarantine);
        if ($quarantined !== []) {
            $this->line('En quarantena: '.self::names($quarantined).'.');
        }
    }

    /**
     * @param  array<PlannedChange>  $changes
     */
    private static function names(array $changes): string
    {
        return implode(', ', array_map(static fn (PlannedChange $c): string => $c->slug.($c->quarantineDaysLeft === null ? '' : " ({$c->quarantineDaysLeft} d)"), $changes));
    }
}
