<?php

declare(strict_types=1);

namespace App\Console\Commands\Users;

use App\Console\ChangesState;
use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\Identity\UserAdministration;
use App\Enums\UserRole;
use DomainException;

final class UserRoleCommand extends WpupCommand implements ChangesState
{
    protected $signature = 'user:role
        {unix_username}
        {role : admin o operator}
        {--dry-run : Mostra què es faria sense fer-ho}';

    protected $description = "Canvia el rol d'un operador (només admin)";

    protected function ability(): Ability
    {
        return Ability::ManageUsers;
    }

    protected function perform(): int
    {
        $users = app(UserAdministration::class);
        $role = UserRole::tryFrom($this->stringArgument('role'));

        if ($role === null) {
            $this->error('El rol ha de ser admin o operator.');

            return self::FAILURE;
        }

        try {
            $user = $users->find($this->stringArgument('unix_username'));

            if ($this->isDryRun()) {
                $this->info("[dry-run] «{$user->unix_username}» passaria de {$user->role->value} a {$role->value}.");

                return self::SUCCESS;
            }

            $users->changeRole($this->actor(), $user, $role);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("«{$user->unix_username}» ara té el rol {$role->value}.");

        return self::SUCCESS;
    }
}
