<?php

declare(strict_types=1);

namespace App\Console\Commands\Runs;

use App\Console\ChangesState;
use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\Runs\Exceptions\RunNotAcknowledgeable;
use App\Domain\Runs\RunAcknowledgement;
use App\Models\Run;

/**
 * Marks a finished run as reviewed (ADR-0009): the retention stops holding its
 * restore point, which then lasts the usual retention from now.
 */
final class RunAckCommand extends WpupCommand implements ChangesState
{
    protected $signature = 'run:ack
        {run : Id de l\'execució}
        '.self::DRY_RUN;

    protected $description = 'Marca una execució acabada com a revisada; el seu punt de restauració passa a seguir la retenció normal';

    protected function ability(): Ability
    {
        return Ability::RunOperations;
    }

    protected function perform(): int
    {
        $id = $this->stringArgument('run');
        $run = ctype_digit($id) ? Run::query()->with('site')->find((int) $id) : null;
        if ($run === null) {
            $this->error("No hi ha cap execució «{$id}».");

            return self::FAILURE;
        }

        $acknowledgement = app(RunAcknowledgement::class);

        try {
            $acknowledgement->assertAcknowledgeable($run);
        } catch (RunNotAcknowledgeable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($run->reviewed_at !== null) {
            $this->info("L'execució #{$run->id} ja està revisada ({$run->reviewed_at->format('Y-m-d H:i')}).");

            return self::SUCCESS;
        }

        $files = $acknowledgement->backups($run);
        $days = config()->integer('wpup.backups.retention_days');
        $this->line("Execució #{$run->id} ({$run->type->value}, {$run->status->value}) de {$run->targetLabel()}: {$files} fitxers al punt de restauració.");

        if ($this->isDryRun()) {
            $this->info("[dry-run] Es marcaria com a revisada; el punt de restauració caducaria d'aquí a {$days} dies i seguiria la retenció normal.");

            return self::SUCCESS;
        }

        if (! $this->confirm("Marcar-la com a revisada? El seu punt de restauració deixarà d'estar retingut: caducarà d'aquí a {$days} dies i seguirà la retenció normal.", false)) {
            $this->warn('No s\'ha fet res.');

            return self::FAILURE;
        }

        try {
            $extended = $acknowledgement->acknowledge($run, $this->operator, $this->actor());
        } catch (RunNotAcknowledgeable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($extended === null
            ? "L'execució #{$run->id} ja estava revisada."
            : "Execució #{$run->id} revisada; el punt de restauració caduca d'aquí a {$days} dies.");

        return self::SUCCESS;
    }
}
