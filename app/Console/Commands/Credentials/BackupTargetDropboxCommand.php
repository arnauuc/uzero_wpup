<?php

declare(strict_types=1);

namespace App\Console\Commands\Credentials;

use App\Domain\Backups\Dropbox\DropboxSetupException;
use App\Domain\Backups\Dropbox\DropboxTargetSetup;

/**
 * Creates or updates a Dropbox backup destination (DropboxTargetSetup). The
 * credential ({app_key, app_secret, refresh_token}) goes to the SecretStore;
 * the three values are typed without echo, never given as arguments (history
 * and `ps`).
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
        $root = $this->stringOption('root') ?? '';
        $setup = app(DropboxTargetSetup::class);

        try {
            $existing = $setup->check($name, $root);
        } catch (DropboxSetupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->isDryRun()) {
            $this->info($existing === null
                ? "[dry-run] Es crearia la destinació de Dropbox «{$name}» (carpeta «{$root}»); es demanarien les credencials i es comprovarien amb Dropbox."
                : "[dry-run] S'actualitzaria la destinació «{$name}» (carpeta «{$root}»); es demanarien les credencials, es comprovarien amb Dropbox i se substituirien.");

            return self::SUCCESS;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Cal un terminal interactiu per escriure les credencials sense eco.');

            return self::FAILURE;
        }

        $values = [];
        foreach (['app_key' => 'App key', 'app_secret' => 'App secret', 'refresh_token' => 'Refresh token'] as $field => $label) {
            $typed = $this->secret($label);
            $values[$field] = is_string($typed) ? $typed : '';
        }

        try {
            $result = $setup->apply($name, $root, $values, $this->actor());
        } catch (DropboxSetupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($result->created
            ? "Destinació de Dropbox «{$name}» creada; credencials comprovades i desades xifrades."
            : "Destinació de Dropbox «{$name}» actualitzada; credencials comprovades i substituïdes.");

        return self::SUCCESS;
    }
}
