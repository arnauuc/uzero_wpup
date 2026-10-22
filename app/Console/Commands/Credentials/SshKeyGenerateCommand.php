<?php

declare(strict_types=1);

namespace App\Console\Commands\Credentials;

use App\Domain\Credentials\CredentialException;
use App\Domain\Credentials\SshKeyManager;

final class SshKeyGenerateCommand extends CredentialCommand
{
    protected $signature = 'ssh-key:generate
        {server : Nom del servidor d\'hosting}
        {account : Usuari de sistema del compte (subscripció de Plesk)}
        '.self::DRY_RUN;

    protected $description = 'Genera la clau SSH d\'un compte d\'hosting i mostra la línia per a l\'authorized_keys';

    protected function perform(): int
    {
        $account = $this->account();
        if ($account === null) {
            return self::FAILURE;
        }

        try {
            $line = app(SshKeyManager::class)->generate($account, $this->actor(), $this->isDryRun());
        } catch (CredentialException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->isDryRun()) {
            $this->info('[dry-run] Es generaria una clau Ed25519 i caldria instal·lar una línia com aquesta:');
        } else {
            $this->info('Clau generada i desada xifrada. Afegeix aquesta línia a ~/.ssh/authorized_keys del compte (o al panell de Plesk):');
        }
        $this->line($line);
        $this->line('Després, comprova-ho amb: wpup site:test <lloc>');

        return self::SUCCESS;
    }
}
