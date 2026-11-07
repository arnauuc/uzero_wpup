<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Backups\BackupStep;
use App\Domain\Backups\RestorePointGateStep;
use App\Domain\Maintenance\MaintenanceSteps;
use App\Domain\Planning\PlanStep;
use App\Domain\Planning\UpdatePlan;
use App\Domain\Rollback\RollbackSteps;
use App\Domain\Runs\Exceptions\RunException;
use App\Domain\Runs\OnFailure;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\Step;
use App\Enums\RunStepKind;
use App\Enums\RunStepStatus;
use App\Enums\UpdateStrategy;
use App\Models\Run;
use App\Models\RunStep;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * The pipeline of an update run with the in-place strategy (UZEROWPUP-60,
 * spec "Estratègies d'actualització"). It grows with the run (see Pipeline):
 *
 *   inventory → plan
 *     nothing to apply (all up to date, skipped, frozen, blocked…): the run
 *     ends here, the site was only read (ADR-0006 §3: no time spent).
 *   → maintenance check (read-only: a site already in maintenance is not
 *     updated; the run fails there, with no restore point, touching nothing)
 *   → backup (verified restore point with the orders watermark)
 *   → PHP log baseline (UZEROWPUP-56; reads only)
 *   → restore-point-gate (AGENTS.md rule 3)
 *   → maintenance on (UZEROWPUP-53, when the profile asks for it)
 *   → component updates, in the plan's order → post-update
 *   → maintenance off (the checks must see the real site)
 *   → smoke check (UZEROWPUP-56: home over HTTP and new fatals in the web's
 *     PHP log; the full verifier against the baseline comes in F4)
 *   → maintenance restore (as it was before the run)
 *
 * Any failure from the first component update to the smoke check runs the
 * recovery branch, as steps of the run itself and in this order: the
 * maintenance mode on, ALWAYS, whatever the profile (a shop is never rolled
 * back open, AGENTS.md rule 4), the files, then the database only when
 * nothing was written to the orders or users since the copy, then the
 * maintenance mode as it was before the run. So the mode stays on during the
 * whole rollback, which compensations in reverse order could not ensure. The run
 * ends rolled_back and its restore point stays protected
 * (RestorePointRetention). A recovery step that fails stops the branch: the
 * site is left in maintenance for an operator.
 *
 * A failure before (inventory, plan, check, backup, baseline, gate) changed
 * nothing on the site: the run fails. A failure switching the mode on stops
 * the run too, without compensations (the step only switches it when it can,
 * and never touches a mode it did not switch). A failure of the last step
 * (the mode could not be put back) stops the run as failed WITHOUT undoing
 * the verified update.
 *
 * The plan is the one the plan step of the SAME run stored: the pipeline is
 * rebuilt from it, identical, when the run resumes. The steps of the staging
 * strategy come in F7: a run with it is refused before it starts.
 */
final readonly class InPlaceUpdatePipeline
{
    /**
     * @param  Step  $inventory  the fresh inventory (InventoryStep, probing the web and blocking)
     * @param  Step  $plan  the plan (PlanStep, with the static analysis that fetches the artifacts);
     *                      its name must be PlanStep::NAME, where the pipeline reads the stored plan
     *
     * @throws InvalidArgumentException when the plan step has another name
     */
    public function __construct(
        private Step $inventory,
        private Step $plan,
        private BackupStep $backup,
        private RestorePointGateStep $gate,
        private UpdateSteps $updates,
        private SmokeCheckSteps $smoke,
        private MaintenanceSteps $maintenance,
        private RollbackSteps $rollback,
    ) {
        if ($plan->name() !== PlanStep::NAME) {
            throw new InvalidArgumentException('The plan step of an update must be named '.PlanStep::NAME.'.');
        }
    }

    /**
     * @throws RunException when the run cannot be updated in place
     */
    public function for(Run $run): Pipeline
    {
        if (($run->strategy ?? UpdateStrategy::InPlace) !== UpdateStrategy::InPlace) {
            throw new RunException("L'estratègia «{$run->strategy?->value}» encara no està disponible (arriba a F7): aquesta execució només pot ser in-place.");
        }

        $prefix = [$this->inventory, $this->plan];
        // The rest of the pipeline is built from the plan this run stores.
        $growsAfter = [PlanStep::NAME];
        $plan = self::storedPlan($run);
        $updates = $plan === null ? [] : $this->updates->for($plan);
        if ($updates === []) {
            return new Pipeline($prefix, [], [], $growsAfter);
        }

        $check = $this->maintenance->check();
        $on = $this->maintenance->on();
        $changing = [...$updates, $this->maintenance->off(), $this->smoke->check()];
        $restore = $this->maintenance->restore();
        $onFailure = [$check->name() => OnFailure::Stop, $on->name() => OnFailure::Stop, $restore->name() => OnFailure::Stop];
        foreach ($changing as $step) {
            $onFailure[$step->name()] = OnFailure::Recover;
        }

        return new Pipeline(
            [...$prefix, $check, $this->backup, $this->smoke->baseline(), $this->gate, $on, ...$changing, $restore],
            [$this->maintenance->forceOn(), ...$this->rollback->steps(), $restore],
            $onFailure,
            $growsAfter,
        );
    }

    /**
     * The plan this run's plan step stored; null while it has not run.
     *
     * @throws RunException when the stored plan cannot be read
     */
    private static function storedPlan(Run $run): ?UpdatePlan
    {
        /** @var RunStep|null $row */
        $row = $run->steps()
            ->where('kind', RunStepKind::Forward->value)
            ->where('name', PlanStep::NAME)
            ->where('status', RunStepStatus::Succeeded->value)
            ->first();
        if ($row === null) {
            return null;
        }

        try {
            return UpdatePlan::fromArray($row->result ?? []);
        } catch (UnexpectedValueException $e) {
            throw new RunException("El pla desat per l'execució no es pot llegir: {$e->getMessage()}");
        }
    }
}
