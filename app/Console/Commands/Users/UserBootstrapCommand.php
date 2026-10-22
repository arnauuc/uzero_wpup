<?php

declare(strict_types=1);

namespace App\Console\Commands\Users;

use App\Domain\Audit\AuditActor;
use App\Domain\Identity\UserAdministration;
use App\Enums\UserRole;
use App\Models\User;
use DomainException;
use Illuminate\Console\Command;

/**
 * Creates the first admin. Only reachable through `artisan` as the service user
 * (not through `wpup`, see CliCommandGate) and only while there is no admin.
 */
final class UserBootstrapCommand extends Command
{
    protected $signature = 'user:bootstrap {unix_username} {--name=}';

    protected $description = 'Crea el primer admin (només si encara no n\'hi ha cap)';

    public function handle(UserAdministration $users): int
    {
        if (User::query()->where('role', UserRole::Admin->value)->exists()) {
            $this->error('Ja hi ha un admin: fes servir «wpup user:add» amb aquell usuari.');

            return self::FAILURE;
        }

        $username = $this->argument('unix_username');
        $name = $this->option('name');

        try {
            $user = $users->add(
                AuditActor::system('sistema (bootstrap)'),
                is_string($username) ? $username : '',
                UserRole::Admin,
                is_string($name) && $name !== '' ? $name : null,
                null,
            );
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Primer admin creat: «{$user->unix_username}».");

        return self::SUCCESS;
    }
}
