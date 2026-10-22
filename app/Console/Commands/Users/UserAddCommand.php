<?php

declare(strict_types=1);

namespace App\Console\Commands\Users;

use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\Identity\UserAdministration;
use App\Enums\UserRole;
use DomainException;

final class UserAddCommand extends WpupCommand
{
    protected $signature = 'user:add
        {unix_username : Usuari Unix de la persona al servidor de l\'eina}
        {--role=operator : admin o operator}
        {--name= : Nom complet}
        {--email= : Correu per a les notificacions}
        {--dry-run : Mostra què es faria sense fer-ho}';

    protected $description = "Dona d'alta un operador (només admin)";

    protected function ability(): Ability
    {
        return Ability::ManageUsers;
    }

    protected function perform(): int
    {
        $users = app(UserAdministration::class);
        $username = $this->stringArgument('unix_username');
        $role = UserRole::tryFrom($this->stringOption('role') ?? '');

        if ($role === null) {
            $this->error('El rol ha de ser admin o operator.');

            return self::FAILURE;
        }

        if ($this->isDryRun()) {
            try {
                $users->assertValidUsername($username);
            } catch (DomainException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->info("[dry-run] Es donaria d'alta «{$username}» amb el rol {$role->value}.");

            return self::SUCCESS;
        }

        try {
            $user = $users->add($this->actor(), $username, $role, $this->stringOption('name'), $this->stringOption('email'));
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Usuari «{$user->unix_username}» donat d'alta amb el rol {$role->value}.");

        return self::SUCCESS;
    }
}
