<?php

declare(strict_types=1);

namespace App\Console\Commands\Backups;

use App\Console\ChangesState;
use App\Console\Concerns\LaunchesRuns;
use App\Console\Concerns\PrintsRunHeader;
use App\Console\Concerns\SelectsSites;
use App\Console\WpupCommand;
use App\Domain\Backups\BackupReport;
use App\Domain\Backups\BackupRow;
use App\Domain\Backups\ByteSize;
use App\Domain\Identity\Ability;
use App\Enums\RunType;
use App\Models\Run;
use App\Models\Site;

/**
 * Manual restore point of one or more sites as queued runs (UZEROWPUP-45). It
 * writes on the site (dump and archive), so it takes --dry-run.
 */
final class BackupCommand extends WpupCommand implements ChangesState
{
    protected $signature = 'backup
        '.self::TARGETS.'
        '.self::DRY_RUN.'
        '.self::QUEUED.'
        '.self::JSON;

    protected $description = 'Fa un punt de restauració (BD i codi, amb SHA-256 verificat) dels llocs';

    use LaunchesRuns;
    use PrintsRunHeader;
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

        // Only a site named explicitly can be inactive (--tag and --all select active ones).
        $active = $sites->filter(static fn (Site $site): bool => $site->active)->values();
        foreach ($sites->reject(static fn (Site $site): bool => $site->active) as $inactive) {
            $this->error("{$inactive->name}: inactiu, no se'n fa còpia.");
        }
        if ($active->isEmpty()) {
            return self::FAILURE;
        }

        $present = function (array $runs): int {
            /** @var list<Run> $runs */
            $report = app(BackupReport::class);
            $rows = $report->rows($runs);

            if ($this->wantsJson()) {
                $this->emitJson($report->document($rows));
            } else {
                array_map($this->printRow(...), $rows);
            }

            // A run that left no restore point is not a success, unless it was a dry run.
            return array_filter($rows, static fn (BackupRow $row): bool => ! $row->hasPoint() && $row->note === null) === [] ? 0 : self::FAILURE;
        };

        return $this->launchRuns(RunType::Backup, array_values($active->all()), ['dry_run' => $this->isDryRun()], null, $present);
    }

    private function printRow(BackupRow $row): void
    {
        $this->printRunHeader($row->site, $row->url, $row->runId, $row->runStatus, $row->failure, 'La còpia');

        if ($row->note !== null) {
            $this->line($row->note);
        }
        if (! $row->hasPoint()) {
            return;
        }

        $this->table(['Tipus', 'Destinació', 'Mida', 'SHA-256', 'Verificat', 'Caduca'], array_map(static fn (array $f): array => [
            $f['kind'],
            $row->targetNames[$f['backup_target_id']] ?? "#{$f['backup_target_id']}",
            ByteSize::human($f['size_bytes']),
            substr($f['sha256'], 0, 12),
            $f['verified'] ? 'sí' : 'no',
            $f['expires_at'] ?? '—',
        ], $row->files));
        foreach ($row->warnings as $warning) {
            $this->warn($warning);
        }
    }
}
