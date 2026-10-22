<?php

declare(strict_types=1);

namespace App\Console\Commands\Credentials;

use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStore;
use App\Enums\AuditResult;
use App\Enums\SiteDriverType;

/**
 * SFTP password of an account that cannot use keys (ADR-0002, point 6). Typed
 * without echo, never as an argument (it would end up in history and `ps`).
 */
final class SecretsSetPasswordCommand extends CredentialCommand
{
    protected $signature = 'secrets:set-password
        {server : Nom del servidor d\'hosting}
        {account : Usuari de sistema del compte}
        '.self::DRY_RUN;

    protected $description = 'Desa la contrasenya SFTP d\'un compte (s\'escriu sense eco)';

    protected function perform(): int
    {
        $account = $this->account();
        if ($account === null) {
            return self::FAILURE;
        }
        if ($account->driver !== SiteDriverType::Agent) {
            $this->error('Aquest compte fa servir SSH amb clau: no cal contrasenya (ssh-key:generate).');

            return self::FAILURE;
        }
        if ($this->isDryRun()) {
            $this->info('[dry-run] Es demanaria la contrasenya i es desaria xifrada.');

            return self::SUCCESS;
        }

        $first = $this->secret('Contrasenya SFTP');
        $second = $this->secret('Repeteix-la');
        if (! is_string($first) || $first === '' || $first !== $second) {
            $this->error('Les contrasenyes no coincideixen o són buides; no s\'ha desat res.');

            return self::FAILURE;
        }

        $secret = new Secret($first);
        $store = app(SecretStore::class);
        try {
            if ($account->credential_ref === null) {
                $account->forceFill(['credential_ref' => $store->put('sftp-password', $secret, $this->actor())])->save();
            } else {
                $store->replace($account->credential_ref, $secret, $this->actor());
            }
        } finally {
            $secret->wipe();
        }
        $this->audit->record($this->actor(), 'sftp_password.set', AuditResult::Success, 'hosting_account', $account->id);
        $this->info('Contrasenya desada xifrada.');

        return self::SUCCESS;
    }
}
