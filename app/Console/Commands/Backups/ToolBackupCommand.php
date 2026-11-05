<?php

declare(strict_types=1);

namespace App\Console\Commands\Backups;

use App\Console\ChangesState;
use App\Console\Concerns\WaitsForWorkers;
use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\ToolBackup\ToolBackupCheckStep;
use App\Domain\ToolBackup\ToolBackupLauncher;
use App\Domain\ToolBackup\ToolBackupStep;
use App\Enums\RunStatus;
use App\Enums\RunStepStatus;
use App\Models\Run;
use App\Models\RunStep;

/**
 * `wpup tool:backup`: the tool's own backup now (UZEROWPUP-47), besides the
 * daily one. Opening the Dropbox destination needs the KEK private key, so it
 * is a run for the workers, and the command waits for it (ADR-0011).
 * docs/runbooks/copia-eina.md
 */
final class ToolBackupCommand extends WpupCommand implements ChangesState
{
    use WaitsForWorkers;

    protected $signature = 'tool:backup '.self::DRY_RUN;

    protected $description = "Fa ara la còpia de l'eina (BD, configuració i informes), xifrada amb age, a Dropbox";

    protected function ability(): Ability
    {
        return Ability::RunOperations;
    }

    protected function perform(): int
    {
        $run = app(ToolBackupLauncher::class)->launch($this->actor(), $this->isDryRun());
        $this->line("Execució #{$run->id} encuada.");

        $timeout = config()->integer('wpup.tool_backup.check_timeout_seconds') + config()->integer('wpup.tool_backup.step_timeout_seconds');
        $final = $this->waitForWorkers([$run], $timeout, function (Run $run, RunStep $step): void {
            if ($step->status->isFinished() && $step->summary !== null && $step->summary !== '') {
                $this->line("{$step->name}: {$step->summary}");
            }
        })[0] ?? null;
        if ($final === null) {
            return self::FAILURE;
        }

        // The paths left out (and failed deletions): from the backup, or from the check in a dry run.
        $step = $final->steps()->whereIn('name', [ToolBackupCheckStep::NAME, ToolBackupStep::NAME])
            ->where('status', RunStepStatus::Succeeded)->orderByDesc('sequence')->first();
        $warnings = $step?->result['warnings'] ?? [];
        foreach (is_array($warnings) ? array_filter($warnings, is_string(...)) : [] as $warning) {
            $this->warn($warning);
        }

        if ($final->status !== RunStatus::Succeeded) {
            $this->error($final->failureReason() ?? "L'execució #{$final->id} ha acabat com a {$final->status->value}.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
