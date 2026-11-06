<?php

declare(strict_types=1);

namespace App\Console\Commands\Planning;

use App\Console\Concerns\LaunchesRuns;
use App\Console\Concerns\PrintsRunHeader;
use App\Console\Concerns\SelectsSites;
use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Planning\PlanOptions;
use App\Domain\Planning\PlanReport;
use App\Domain\Planning\PlanRow;
use App\Domain\Planning\QuarantineBypass;
use App\Enums\AuditResult;
use App\Enums\RunType;
use App\Enums\SkipReason;
use App\Models\Run;
use App\Models\Site;

/**
 * Plan of the updates of one or more sites as queued runs (UZEROWPUP-36). It
 * changes neither the content of the site nor its block state (no probe, no block
 * change), so it is not a ChangesState command and has no --dry-run. It does
 * write to the tool (run, steps, snapshot, audit rows), and the driver may install
 * the tool's WP-CLI in its working directory on the site's account.
 *
 * --allow-major and --skip-quarantine (ADR-0005, UZEROWPUP-127) are for an admin
 * only, audited, and stored in runs.flags so the plan, the update run and the
 * report all see them.
 */
final class PlanCommand extends WpupCommand
{
    protected $signature = 'plan
        '.self::TARGETS.'
        {--allow-major : Només admin: aplica in-place els canvis majors que cal provar abans a staging (ADR-0005)}
        {--skip-quarantine : Només admin: aplica les versions que encara són en quarantena}
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
        $options = new PlanOptions($this->option('allow-major') === true, $this->option('skip-quarantine') === true);
        if ($options->any() && ! $this->requireAbility(Ability::OverrideVersionPolicy, ['flags' => $options->toFlags()], 'Només un admin pot fer servir --allow-major i --skip-quarantine.')) {
            return self::FAILURE;
        }

        $active = $this->activeSelectedSites('no es planifica');
        if ($active === null) {
            return self::FAILURE;
        }

        if ($options->any()) {
            $this->audit->record($this->actor(), 'plan.version_policy_overridden', AuditResult::Success, 'command', (string) $this->getName(), [
                'flags' => $options->toFlags(),
                'sites' => array_map(static fn (Site $site): string => $site->name, $active),
            ]);
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

        return $this->exitCodeWithSkipped($this->launchRuns(RunType::Plan, $active, $options->toFlags(), null, $present));
    }

    /**
     * Why it is skipped, or what is special about an update (security exception, override).
     */
    private static function reason(PlannedChange $change): string
    {
        // The bypass label only when there is no note: the note already says it.
        $label = $change->reason?->label()
            ?? ($change->bypass === null || $change->bypass === QuarantineBypass::CoreMaintenance || $change->detail !== null ? null : $change->bypass->label());

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
        foreach ($plan->environmentWarnings as $warning) {
            $this->warn('Avís d\'entorn: '.$warning);
        }
        if ($plan->options->any()) {
            $this->line('Sobreescriptures (auditades): '.implode(', ', array_keys(array_filter([
                '--allow-major' => $plan->options->allowMajor,
                '--skip-quarantine' => $plan->options->skipQuarantine,
            ]))).'.');
        }
        $this->line('Cal staging primer: '.match (true) {
            ! $plan->requiresStaging() => 'no',
            $plan->options->allowMajor => 'sí, però --allow-major aplica els majors in-place',
            default => 'sí: els canvis que l\'exigeixen s\'ometen fins a F7',
        });

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
        $this->line($plan->summary().'.');

        $quarantined = array_filter($plan->changes, static fn (PlannedChange $c): bool => $c->reason === SkipReason::Quarantine);
        $security = array_filter($plan->changes, static fn (PlannedChange $c): bool => $c->bypass === QuarantineBypass::Vulnerability);
        if ($quarantined !== []) {
            $this->line('En quarantena: '.self::names($quarantined).'.');
        }
        if ($security !== []) {
            $this->line('Excepcions de seguretat: '.self::names($security).'.');
        }
        if ($plan->appliedMajors() !== []) {
            $this->warn('Majors aplicades: cal revisar: '.self::names($plan->appliedMajors()).'.');
        }
    }

    /**
     * @param  array<PlannedChange>  $changes
     */
    private static function names(array $changes): string
    {
        return implode(', ', array_map(static fn (PlannedChange $c): string => $c->slug.($c->quarantineDaysLeft === null || $c->action !== PlanAction::Skip ? '' : " ({$c->quarantineDaysLeft} d)"), $changes));
    }
}
