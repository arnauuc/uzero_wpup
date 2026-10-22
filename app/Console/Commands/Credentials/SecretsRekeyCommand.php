<?php

declare(strict_types=1);

namespace App\Console\Commands\Credentials;

use App\Domain\Credentials\SecretsRekey;
use App\Domain\Secrets\SecretStoreException;

final class SecretsRekeyCommand extends CredentialCommand
{
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

        try {
            $done = $rekey->run($this->actor(), function (int $done, int $total): void {
                if ($done === $total || $done % 50 === 0) {
                    $this->line("  {$done}/{$total}");
                }
            });
        } catch (SecretStoreException $e) {
            $this->error("S'ha aturat: {$e->getMessage()} Torna-ho a executar per continuar.");

            return self::FAILURE;
        }

        $this->info("Fet: {$done} claus de dades tornades a xifrar. Ja pots retirar la KEK antiga (vegeu el runbook).");

        return self::SUCCESS;
    }
}
