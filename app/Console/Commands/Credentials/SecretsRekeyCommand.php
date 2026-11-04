<?php

declare(strict_types=1);

namespace App\Console\Commands\Credentials;

use App\Console\Concerns\WaitsForWorkers;
use App\Domain\Credentials\SecretsRekey;
use App\Domain\Credentials\SecretsRekeyStep;
use App\Domain\Runs\RunLauncher;
use App\Enums\RunStatus;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Models\Run;
use App\Models\RunStep;

final class SecretsRekeyCommand extends CredentialCommand
{
    use WaitsForWorkers;

    protected $signature = 'secrets:rekey '.self::DRY_RUN;

    protected $description = 'Torna a xifrar totes les claus de dades amb la KEK vigent (docs/runbooks/rotacio-kek.md)';

    protected function perform(): int
    {
        $rekey = app(SecretsRekey::class);
        $pending = $rekey->pending();

        if ($pending === 0) {
            $this->info('Tots els secrets ja són amb la KEK vigent.');

            return self::SUCCESS;
        }
        if ($this->isDryRun()) {
            $this->info("[dry-run] Es tornarien a xifrar {$pending} claus de dades.");

            return self::SUCCESS;
        }
        if (! $this->confirm("Tornar a xifrar {$pending} claus de dades amb la KEK vigent?", true)) {
            return self::FAILURE;
        }

        // Opening the data keys needs the old private key, which only the workers have (ADR-0011).
        $run = app(RunLauncher::class)->launch(RunType::SecretsRekey, [null], $this->actor())[0];
        $timeout = config('wpup.credential_operations.secrets_rekey_timeout_seconds');
        $final = $this->waitForWorkers([$run], is_int($timeout) ? $timeout : 3600, function (Run $run, RunStep $step): void {
            if ($step->name === SecretsRekeyStep::NAME && $step->status === RunStepStatus::Running && $step->summary !== null) {
                $this->line($step->summary);
            }
        })[0] ?? null;
        if ($final === null) {
            return self::FAILURE;
        }
        $step = $final->steps()->where('name', SecretsRekeyStep::NAME)->first();

        if ($final->status !== RunStatus::Succeeded) {
            $reason = $final->result_summary['reason'] ?? null;
            $this->error($step->summary ?? (is_string($reason) ? $reason : "L'execució #{$final->id} ha acabat com a {$final->status->value}."));

            return self::FAILURE;
        }

        $done = $step?->result['rewrapped'] ?? 0;
        $done = is_int($done) ? $done : 0;
        $this->info("Fet: {$done} claus de dades tornades a xifrar. Ja pots retirar la KEK antiga (vegeu el runbook).");

        return self::SUCCESS;
    }
}
