<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use App\Console\ChangesState;
use App\Console\Concerns\LaunchesRuns;
use App\Console\Concerns\SelectsSites;
use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\Inventory\InventoryExportException;
use App\Domain\Inventory\InventoryReport;
use App\Domain\Inventory\InventoryRow;
use App\Enums\RunType;
use App\Models\Run;

/**
 * Inventory of one or more sites as queued runs (UZEROWPUP-35). It writes a
 * temporary probe to the site and may change the site's block, so it takes
 * --dry-run like the other commands that change state.
 */
final class InventoryCommand extends WpupCommand implements ChangesState
{
    protected $signature = 'inventory
        '.self::TARGETS.'
        '.self::DRY_RUN.'
        '.self::QUEUED.'
        '.self::JSON.'
        {--csv= : Escriu l\'inventari en CSV a aquest camí (cal --wait o --follow)}';

    protected $description = 'Inventari de versions, PHP real i actualitzacions pendents dels llocs';

    use LaunchesRuns;
    use SelectsSites;

    protected function ability(): Ability
    {
        return Ability::RunOperations;
    }

    protected function perform(): int
    {
        $csv = $this->stringOption('csv');
        if ($csv !== null) {
            if ($this->option('wait') !== true && $this->option('follow') !== true) {
                $this->error('--csv necessita --wait o --follow: l\'inventari s\'ha d\'esperar per poder-lo exportar.');

                return self::FAILURE;
            }
            try {
                InventoryReport::assertWritable($csv);
            } catch (InventoryExportException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $sites = $this->selectedSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $exportFailed = false;
        $present = function (array $runs) use ($csv, &$exportFailed): void {
            /** @var list<Run> $runs */
            $report = app(InventoryReport::class);
            $rows = $report->rows($runs);

            if ($csv !== null) {
                try {
                    $report->writeCsv($csv, $rows);
                } catch (InventoryExportException $e) {
                    $exportFailed = true;
                    $this->error($e->getMessage());
                }
            }

            if ($this->wantsJson()) {
                $this->emitJson($report->document($rows));
            } else {
                array_map($this->printRow(...), $rows);
                if ($csv !== null && ! $exportFailed) {
                    $this->line("CSV escrit a {$csv}.");
                }
            }
        };

        $exit = $this->launchRuns(RunType::Inventory, array_values($sites->all()), ['dry_run' => $this->isDryRun()], null, $present);

        return $exportFailed ? self::FAILURE : $exit;
    }

    private function printRow(InventoryRow $row): void
    {
        $this->line('');
        $this->line("<options=bold>{$row->site}</> ({$row->url}) — execució #{$row->runId}");

        if (! $row->hasSnapshot()) {
            $this->error("L'inventari ha fallat ({$row->runStatus}): {$row->failure}");

            return;
        }

        $lines = [
            ['WordPress', $row->core],
            ['WooCommerce', $row->woocommerce ?? 'no instal·lat'],
            ['PHP', "{$row->php} ({$row->phpSource})".($row->phpWarnings === [] ? '' : ', '.count($row->phpWarnings).' avisos')],
            ['Base de dades', $row->dbServer],
            ['Actualitzacions pendents', (string) $row->pendingUpdates.($row->unlicensed > 0 ? " ({$row->unlicensed} sense llicència)" : '')],
            ['Estat', self::notes($row)],
            ['Bloquejat', $row->blocked ? "sí: {$row->blockedReason}" : 'no'],
        ];
        $this->table(['Camp', 'Valor'], $lines);
    }

    private static function notes(InventoryRow $row): string
    {
        $notes = [];
        if ($row->maintenance) {
            $notes[] = 'manteniment actiu';
        }
        if ($row->wcDbUpdateNeeded) {
            $notes[] = 'BD de WooCommerce pendent d\'actualitzar';
        }
        if ($row->outdatedTemplates > 0) {
            $notes[] = "{$row->outdatedTemplates} plantilles desfasades";
        }

        return $notes === [] ? 'normal' : implode('; ', $notes);
    }
}
