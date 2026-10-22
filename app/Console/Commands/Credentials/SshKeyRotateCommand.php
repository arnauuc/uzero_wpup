<?php

declare(strict_types=1);

namespace App\Console\Commands\Credentials;

use App\Domain\Credentials\CredentialException;
use App\Domain\Credentials\SshKeyManager;

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

        try {
            $notes = app(SshKeyManager::class)->rotate($account, $this->actor());
        } catch (CredentialException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Clau rotada: la nova funciona i l\'antiga s\'ha retirat.');
        foreach ($notes as $note) {
            $this->warn($note);
        }

        return self::SUCCESS;
    }
}
