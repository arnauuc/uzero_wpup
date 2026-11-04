<?php

declare(strict_types=1);

namespace App\Console\Commands\Credentials;

use App\Domain\Credentials\KeyRotationStep;
use App\Domain\Runs\RunFollower;
use App\Domain\Runs\RunLauncher;
use App\Enums\RunStatus;
use App\Enums\RunType;

final class SshKeyRotateCommand extends CredentialCommand
{
    protected $signature = 'ssh-key:rotate
        {server : Nom del servidor d\'hosting}
        {account : Usuari de sistema del compte}
        '.self::DRY_RUN;

    protected $description = 'Substitueix la clau SSH d\'un compte (reprenible; sempre es manté una clau que funciona)';

    protected function perform(): int
    {
        $account = $this->account();
        if ($account === null) {
            return self::FAILURE;
        }

        if ($this->isDryRun()) {
            $state = match (true) {
                $account->retiring_credential_ref !== null => 'Es reprendria la rotació: falta retirar la clau antiga.',
                $account->pending_credential_ref !== null => 'Es reprendria la rotació: la clau nova encara no és la vigent.',
                default => 'Es generaria una clau nova, s\'instal·laria amb l\'actual, es provaria i es retiraria l\'antiga.',
            };
            $this->info("[dry-run] {$state}");

            return self::SUCCESS;
        }

        // Revealing the keys needs the private KEK, which only the workers have (ADR-0011).
        $run = app(RunLauncher::class)->launchForAccount(RunType::KeyRotation, $account, $this->actor());
        $final = app(RunFollower::class)->wait([$run->id])[0];
        $step = $final->steps()->where('name', KeyRotationStep::NAME)->first();

        if ($final->status !== RunStatus::Succeeded) {
            $reason = $final->result_summary['reason'] ?? null;
            $this->error($step->summary ?? (is_string($reason) ? $reason : "L'execució #{$final->id} ha acabat com a {$final->status->value}."));

            return self::FAILURE;
        }

        $this->info('Clau rotada: la nova funciona i l\'antiga s\'ha retirat.');
        $notes = $step?->result['notes'] ?? [];
        $notes = is_array($notes) ? $notes : [];
        foreach ($notes as $note) {
            $this->warn(is_string($note) ? $note : '');
        }

        return self::SUCCESS;
    }
}
