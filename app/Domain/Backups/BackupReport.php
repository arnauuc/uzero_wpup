<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Runs\ReportVersion;
use App\Enums\RunStepStatus;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Run;
use App\Models\RunStep;
use Illuminate\Database\Eloquent\Collection;

/**
 * Turns finished backup runs into rows for the terminal and JSON (UZEROWPUP-45).
 * The restore point is the summary the run stored in its `backup` step.
 */
final class BackupReport
{
    /**
     * @param  list<Run>  $runs  finished runs, with their steps
     * @return list<BackupRow>
     */
    public function rows(array $runs): array
    {
        (new Collection($runs))->loadMissing('site');
        /** @var array<int, string> $names */
        $names = BackupTargetRecord::query()->pluck('name', 'id')->all();

        return array_map(fn (Run $run): BackupRow => $this->row($run, $names), $runs);
    }

    /**
     * @param  list<BackupRow>  $rows
     * @return array<string, mixed>
     */
    public function document(array $rows): array
    {
        return [
            'version' => ReportVersion::read('wpup.backups.report_version'),
            'sites' => array_map(static fn (BackupRow $row): array => $row->toArray(), $rows),
        ];
    }

    /**
     * @param  array<int, string>  $names
     */
    private function row(Run $run, array $names): BackupRow
    {
        $failure = $run->failureReason();
        $step = $run->steps->firstWhere('name', BackupStep::NAME);
        $dryRun = ($run->flags['dry_run'] ?? false) === true;

        $result = $step instanceof RunStep && $step->status === RunStepStatus::Succeeded && is_array($step->result) ? $step->result : null;
        $note = null;
        if ($result === null) {
            if ($dryRun && $step instanceof RunStep && $step->status === RunStepStatus::Skipped) {
                $note = $step->summary;
            } else {
                $failure ??= "L'execució no ha deixat cap punt de restauració.";
            }
        }

        /** @var list<array{id: int, backup_target_id: int, kind: string, key: string, size_bytes: int, sha256: string, verified: bool, expires_at: ?string}> $files */
        $files = is_array($result['backups'] ?? null) ? array_values($result['backups']) : [];
        /** @var list<string> $warnings */
        $warnings = is_array($result['warnings'] ?? null) ? array_values($result['warnings']) : [];
        $directory = is_string($result['directory'] ?? null) ? $result['directory'] : null;

        return new BackupRow($run->targetLabel(), $run->site->url ?? '', $run->id, $run->status, $failure, $directory, $files, $warnings, $names, $note);
    }
}
