<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Runs\ReportVersion;
use App\Enums\RunStepStatus;
use App\Models\Run;
use App\Models\RunStep;
use Illuminate\Database\Eloquent\Collection;
use UnexpectedValueException;

/**
 * Turns finished plan runs into rows for the terminal and JSON (UZEROWPUP-36).
 * The plan is the one the run stored in its `plan` step. It reads the runs it is
 * given (the ones RunFollower returned, with their steps); only the sites are
 * loaded here, in one query.
 */
final class PlanReport
{
    /**
     * @param  list<Run>  $runs  finished runs
     * @return list<PlanRow>
     */
    public function rows(array $runs): array
    {
        (new Collection($runs))->loadMissing('site');

        return array_map($this->row(...), $runs);
    }

    /**
     * @param  list<PlanRow>  $rows
     * @return array<string, mixed>
     */
    public function document(array $rows): array
    {
        return [
            'version' => ReportVersion::read('wpup.planning.report_version'),
            'sites' => array_map(static fn (PlanRow $row): array => $row->toArray(), $rows),
        ];
    }

    private function row(Run $run): PlanRow
    {
        $failure = $run->failureReason();
        $step = $run->steps->firstWhere('name', PlanStep::NAME);

        $plan = null;
        if ($step instanceof RunStep && $step->status === RunStepStatus::Succeeded && is_array($step->result)) {
            try {
                $plan = UpdatePlan::fromArray($step->result);
            } catch (UnexpectedValueException) {
                $failure ??= 'El pla desat no és vàlid.';
            }
        } else {
            $failure ??= "L'execució no ha deixat cap pla.";
        }

        return new PlanRow($run->targetLabel(), $run->site->url ?? '', $run->id, $run->status, $failure, $plan, $run->site === null ? null : $run->site->blocked_reason !== null);
    }
}
