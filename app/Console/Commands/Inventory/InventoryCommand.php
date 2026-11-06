<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use App\Console\ChangesState;
use App\Console\Concerns\LaunchesRuns;
use App\Console\Concerns\PrintsRunHeader;
use App\Console\Concerns\SelectsSites;
use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
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
        {--csv : Escriu l\'inventari en CSV a la sortida estàndard (cal --wait; redirigeix-lo: > fitxer.csv)}';

    protected $description = 'Inventari de versions, PHP real i actualitzacions pendents dels llocs';

    use LaunchesRuns;
    use PrintsRunHeader;
    use SelectsSites;

    protected function ability(): Ability
    {
        return Ability::RunOperations;
    }

    protected function perform(): int
    {
        $csv = $this->option('csv') === true;
        if ($csv) {
            $error = match (true) {
                $this->wantsJson() => '--csv i --json són incompatibles: tria un sol format.',
                $this->option('follow') === true => '--csv no es pot combinar amb --follow: el progrés embrutaria el CSV de la sortida.',
                $this->option('wait') !== true => '--csv necessita --wait: cal esperar l\'inventari per poder-lo exportar.',
                default => null,
            };
            if ($error !== null) {
                $this->error($error);

                return self::FAILURE;
            }
        }

        $sites = $this->selectedSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $present = function (array $runs) use ($csv): ?int {
            /** @var list<Run> $runs */
            $report = app(InventoryReport::class);
            $rows = $report->rows($runs);

            if ($csv) {
                $this->output->write($report->csv($rows));
            } elseif ($this->wantsJson()) {
                $this->emitJson($report->document($rows));
            } else {
                array_map($this->printRow(...), $rows);
            }

            return null;
        };

        return $this->launchRuns(RunType::Inventory, array_values($sites->all()), ['dry_run' => $this->isDryRun()], null, $present);
    }

    private function printRow(InventoryRow $row): void
    {
        $this->printRunHeader($row->site, $row->url, $row->runId, $row->runStatus, $row->failure, "L'inventari");

        if (! $row->hasSnapshot()) {
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
            ['Còpia externa', $row->externalBackupWarnings === [] ? 'ok' : 'AVÍS: '.implode(' ', $row->externalBackupWarnings)],
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
