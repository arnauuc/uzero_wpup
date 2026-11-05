<?php

declare(strict_types=1);

namespace App\Console\Commands\Credentials;

use App\Domain\Backups\Dropbox\DropboxCredential;
use App\Domain\Backups\Dropbox\DropboxException;
use App\Domain\Backups\Dropbox\DropboxTargetFactory;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStore;
use App\Enums\AuditResult;
use App\Enums\BackupTargetType;
use App\Models\BackupTarget;

/**
 * Creates or updates a Dropbox backup destination and stores its credential
 * ({app_key, app_secret, refresh_token}) in the SecretStore. The three values
 * are typed without echo, never given as arguments (history and `ps`).
 */
final class BackupTargetDropboxCommand extends CredentialCommand
{
    protected $signature = 'backup-target:dropbox
        {name : Nom de la destinació}
        {--root= : Carpeta dins de la carpeta de l\'app de Dropbox}
        '.self::DRY_RUN;

    protected $description = 'Crea o actualitza una destinació de còpia a Dropbox (les credencials s\'escriuen sense eco)';

    protected function perform(): int
    {
        $name = trim($this->stringArgument('name'));
        $root = $this->stringOption('root');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $name) !== 1) {
            $this->error('El nom ha de tenir lletres, xifres, «.», «_» o «-» (màxim 128 caràcters).');

            return self::FAILURE;
        }
        if ($root === null || ! DropboxTargetFactory::isValidRoot($root)) {
            $this->error('Cal --root amb una carpeta relativa en minúscules (lletres, xifres, «.», «_», «-» separades per «/»).');

            return self::FAILURE;
        }

        $existing = BackupTarget::query()->where('name', $name)->first();
        if ($existing !== null && $existing->type !== BackupTargetType::Dropbox) {
            $this->error("Ja hi ha una destinació «{$name}» d'un altre tipus; tria un altre nom.");

            return self::FAILURE;
        }

        if ($this->isDryRun()) {
            $this->info($existing === null
                ? "[dry-run] Es crearia la destinació de Dropbox «{$name}» (carpeta «{$root}») i es demanarien les credencials."
                : "[dry-run] S'actualitzaria la carpeta de «{$name}» a «{$root}» i se'n substituirien les credencials.");

            return self::SUCCESS;
        }

        $values = [];
        foreach (['app_key' => 'App key', 'app_secret' => 'App secret', 'refresh_token' => 'Refresh token'] as $field => $label) {
            $typed = $this->secret($label);
            $values[$field] = is_string($typed) ? $typed : '';
        }
        $json = json_encode($values);
        unset($values);

        $secret = new Secret($json === false ? '' : $json);
        unset($json);
        try {
            try {
                $credential = DropboxCredential::fromSecret($secret);
                $credential->appSecret->wipe();
                $credential->refreshToken->wipe();
            } catch (DropboxException $e) {
                // The messages only name the field, never its value.
                $this->error($e->getMessage().' No s\'ha desat res.');

                return self::FAILURE;
            }

            $store = app(SecretStore::class);
            if ($existing === null) {
                $target = BackupTarget::query()->create([
                    'name' => $name,
                    'type' => BackupTargetType::Dropbox,
                    'config' => ['root' => $root],
                    'credential_ref' => $store->put('dropbox-credential', $secret, $this->actor()),
                    'retention' => [],
                    'active' => true,
                ]);
            } else {
                $target = $existing;
                $ref = $existing->credential_ref;
                if ($ref === null || $ref === '') {
                    $ref = $store->put('dropbox-credential', $secret, $this->actor());
                } else {
                    $store->replace($ref, $secret, $this->actor());
                }
                $existing->forceFill(['config' => [...$existing->config, 'root' => $root], 'credential_ref' => $ref])->save();
            }
        } finally {
            $secret->wipe();
        }

        $this->audit->record($this->actor(), 'backup_target.dropbox.set', AuditResult::Success, 'backup_target', (string) $target->id, [
            'name' => $name,
            'root' => $root,
            'created' => $existing === null,
        ]);
        $this->info($existing === null
            ? "Destinació de Dropbox «{$name}» creada; credencials desades xifrades."
            : "Destinació de Dropbox «{$name}» actualitzada; credencials substituïdes.");

        return self::SUCCESS;
    }
}
