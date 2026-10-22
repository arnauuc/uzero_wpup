<?php

declare(strict_types=1);

namespace App\Console\Commands\Users;

use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\Identity\UserAdministration;
use DomainException;

final class UserDeactivateCommand extends WpupCommand
{
    protected $signature = 'user:deactivate
        {unix_username}
        {--dry-run : Mostra què es faria sense fer-ho}';

    protected $description = 'Desactiva un operador; no esborra el seu historial (només admin)';

    protected function ability(): Ability
    {
        return Ability::ManageUsers;
    }

    protected function perform(): int
    {
        $users = app(UserAdministration::class);

        try {
            $user = $users->find($this->stringArgument('unix_username'));

            if ($this->isDryRun()) {
                $this->info("[dry-run] Es desactivaria «{$user->unix_username}».");

                return self::SUCCESS;
            }

            if (! $this->confirm("Desactivar «{$user->unix_username}»?", true)) {
                return self::FAILURE;
            }

            $users->deactivate($this->actor(), $user);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("«{$user->unix_username}» desactivat.");

        return self::SUCCESS;
    }
}
