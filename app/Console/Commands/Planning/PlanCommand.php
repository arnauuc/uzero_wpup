<?php

declare(strict_types=1);

namespace App\Console\Commands\Planning;

use App\Console\Concerns\LaunchesRuns;
use App\Console\Concerns\SelectsSites;
use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\Planning\PlanReport;
use App\Domain\Planning\PlanRow;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Run;

/**
 * Plan of the updates of one or more sites as queued runs (UZEROWPUP-36). It
 * changes nothing on the site nor in the tool (no probe, no block change), so
 * it is not a ChangesState command and has no --dry-run.
 */
final class PlanCommand extends WpupCommand
{
    protected $signature = 'plan
        '.self::TARGETS.'
        '.self::QUEUED.'
        '.self::JSON;

    protected $description = "Mostra què s'actualitzaria i què s'ometria, i per què, sense tocar els llocs";

    use LaunchesRuns;
    use SelectsSites;

    protected function ability(): Ability
    {
        return Ability::RunOperations;
    }

    protected function perform(): int
    {
        $sites = $this->selectedSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $present = function (array $runs): void {
            /** @var list<Run> $runs */
            $report = app(PlanReport::class);
            $rows = $report->rows($runs);

            if ($this->wantsJson()) {
                $this->emitJson($report->document($rows));
            } else {
                array_map($this->printRow(...), $rows);
            }
        };

        return $this->launchRuns(RunType::Plan, array_values($sites->all()), [], null, $present);
    }

    private function printRow(PlanRow $row): void
    {
        $this->line('');
        $this->line("<options=bold>{$row->site}</> ({$row->url}) — execució #{$row->runId}");

        if ($row->runStatus === RunStatus::Skipped) {
            $this->line("omès: {$row->failure}");
        } elseif ($row->runStatus !== RunStatus::Succeeded) {
            $this->error("El pla no ha acabat bé ({$row->runStatus->value}): {$row->failure}");
        }

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
