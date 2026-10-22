<?php

declare(strict_types=1);

namespace App\Console\Commands\Users;

use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Models\User;

final class UserListCommand extends WpupCommand
{
    protected $signature = 'user:list';

    protected $description = 'Llista els operadors (només admin)';

    protected function ability(): Ability
    {
        return Ability::ManageUsers;
    }

    protected function perform(): int
    {
        $rows = User::query()->orderBy('unix_username')->get()->map(fn (User $user): array => [
            $user->unix_username,
            $user->name,
            $user->role->value,
            $user->active ? 'sí' : 'no',
        ]);

        $this->table(['Usuari Unix', 'Nom', 'Rol', 'Actiu'], $rows->all());

        return self::SUCCESS;
    }
}
