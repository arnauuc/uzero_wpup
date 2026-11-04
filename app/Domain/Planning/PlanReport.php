<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Models\RunStep;
use UnexpectedValueException;

/**
 * Turns finished plan runs into rows for the terminal and JSON (UZEROWPUP-36).
 * The plan is the one the run stored in its `plan` step.
 */
final class PlanReport
{
    /**
     * @param  list<Run>  $runs  finished runs
     * @return list<PlanRow>
     */
    public function rows(array $runs): array
    {
        $ids = array_map(static fn (Run $run): int => $run->id, $runs);
        $loaded = Run::query()->whereKey($ids)->with('site')->get()->keyBy('id');
        $steps = RunStep::query()->whereIn('run_id', $ids)->where('name', PlanStep::NAME)->get()->keyBy('run_id');

        $rows = [];
        foreach ($runs as $run) {
            $fresh = $loaded->get($run->id) ?? $run;
            $rows[] = $this->row($fresh, $steps->get($run->id));
        }

        return $rows;
    }

    /**
     * @param  list<PlanRow>  $rows
     * @return array<string, mixed>
     */
    public function document(array $rows): array
    {
        $version = config('wpup.planning.report_version');

        return [
            'version' => is_int($version) ? $version : throw new UnexpectedValueException('wpup.planning.report_version must be an integer.'),
            'sites' => array_map(static fn (PlanRow $row): array => $row->toArray(), $rows),
        ];
    }

    private function row(Run $run, ?RunStep $step): PlanRow
    {
        $name = $run->site->name ?? '(sense lloc)';
        $url = $run->site->url ?? '';
        $failure = $run->status === RunStatus::Succeeded ? null : (is_string($run->result_summary['reason'] ?? null)
            ? $run->result_summary['reason']
            : "L'execució ha acabat com a {$run->status->value}.");

        $plan = null;
        if ($step !== null && $step->status->value === 'succeeded' && is_array($step->result)) {
            $plan = UpdatePlan::fromArray($step->result);
        } else {
            $failure ??= "L'execució no ha deixat cap pla.";
        }

        return new PlanRow($name, $url, $run->id, $run->status, $failure, $plan);
    }
}
