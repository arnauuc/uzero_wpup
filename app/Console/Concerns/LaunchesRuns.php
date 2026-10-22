<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Domain\Runs\RunFollower;
use App\Domain\Runs\RunLauncher;
use App\Enums\RunType;
use App\Enums\UpdateStrategy;
use App\Models\Run;
use App\Models\RunStep;
use App\Models\Site;

/**
 * For commands that queue runs; their signature includes WpupCommand::QUEUED
 * and ::JSON. Without --follow or --wait they print the run ids and return.
 */
trait LaunchesRuns
{
    /**
     * @param  list<Site|null>  $sites
     * @param  array<string, mixed>  $flags
     */
    protected function launchRuns(RunType $type, array $sites, array $flags = [], ?UpdateStrategy $strategy = null): int
    {
        $runs = app(RunLauncher::class)->launch($type, $sites, $this->actor(), $flags, $strategy);
        $follow = $this->option('follow') === true;

        if (! $follow && $this->option('wait') !== true) {
            if ($this->wantsJson()) {
                $this->emitJson(['runs' => array_map(self::runJson(...), $runs)]);
            } else {
                foreach ($runs as $run) {
                    $this->line("Execució #{$run->id} encuada ({$type->value}, ".self::siteLabel($run).').');
                }
                $this->line('Segueix-la amb --follow o consulta-la amb `wpup runs`.');
            }

            return self::SUCCESS;
        }

        $final = app(RunFollower::class)->wait(
            array_map(static fn (Run $run): int => $run->id, $runs),
            $follow && ! $this->wantsJson() ? function (Run $run, RunStep $step): void {
                $prefix = $step->kind->value === 'compensation' ? 'desfent ' : '';
                $summary = $step->summary !== null && $step->summary !== '' ? " — {$step->summary}" : '';
                $this->line("[#{$run->id} ".self::siteLabel($run)."] {$prefix}{$step->name}: {$step->status->value}{$summary}");
            } : null,
        );

        if ($this->wantsJson()) {
            $this->emitJson(['runs' => array_map(self::runJson(...), $final)]);
        } else {
            $this->table(['Execució', 'Lloc', 'Estat', 'Motiu'], array_map(static fn (Run $run): array => [
                "#{$run->id}",
                self::siteLabel($run),
                $run->status->value,
                is_string($run->result_summary['reason'] ?? null) ? $run->result_summary['reason'] : '',
            ], $final));
        }

        return RunFollower::exitCode($final);
    }

    private static function siteLabel(Run $run): string
    {
        return $run->site->name ?? 'flota';
    }

    /**
     * @return array<string, mixed>
     */
    private static function runJson(Run $run): array
    {
        return [
            'id' => $run->id,
            'site' => $run->site?->name,
            'type' => $run->type->value,
            'status' => $run->status->value,
            'result' => $run->result_summary,
        ];
    }
}
