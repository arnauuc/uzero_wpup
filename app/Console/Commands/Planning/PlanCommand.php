<?php

declare(strict_types=1);

namespace App\Console\Commands\Planning;

use App\Console\Concerns\LaunchesRuns;
use App\Console\Concerns\PrintsRunHeader;
use App\Console\Concerns\SelectsSites;
use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\Planning\PlanReport;
use App\Domain\Planning\PlanRow;
use App\Enums\RunType;
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

    private function printRow(PlanRow $row): void
    {
        $this->printRunHeader($row->site, $row->url, $row->runId, $row->runStatus, $row->failure, 'El pla');

        $plan = $row->plan;
        if ($plan === null) {
            return;
        }

        $this->line('PHP '.$plan->phpVersion.' ('.$plan->phpSource->value.')'.($plan->phpVerified ? '' : ': no verificat pel web, és el del CLI'));
        $this->line('Bloquejat: '.($plan->blockedReason === null ? 'no' : "sí: {$plan->blockedReason}"));

        if ($plan->changes === []) {
            $this->line('No hi ha cap actualització pendent.');

            return;
        }

        $this->table(['Component', 'Actual', 'Objectiu', 'Acció', 'Motiu'], array_map(static fn ($c): array => [
            "{$c->type->value}:{$c->slug}",
            $c->currentVersion,
            $c->targetVersion,
            $c->action->label(),
            $c->reason === null ? '' : $c->reason->label().($c->detail === null ? '' : " — {$c->detail}"),
        ], $plan->changes));
        $this->line($plan->summary().'.');
    }
}
