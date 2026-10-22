<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Enums\AuditResult;
use App\Enums\UserRole;
use App\Models\User;
use DomainException;

/**
 * Operator accounts. Shared by the CLI commands and the bootstrap of the first
 * admin; every change is audited.
 */
final readonly class UserAdministration
{
    private const UNIX_USERNAME = '/^[a-z_][a-z0-9_-]{0,31}$/';

    public function __construct(private AuditLogger $audit) {}

    public function add(AuditActor $actor, string $unixUsername, UserRole $role, ?string $name, ?string $email): User
    {
        $this->assertValidUsername($unixUsername);

        if (User::query()->where('unix_username', $unixUsername)->exists()) {
            throw new DomainException(sprintf("L'usuari «%s» ja existeix.", $unixUsername));
        }

        $user = User::query()->create([
            'unix_username' => $unixUsername,
            'name' => $name ?? $unixUsername,
            'email' => $email,
            'role' => $role,
            'active' => true,
        ]);

        $this->audit->record($actor, 'user.add', AuditResult::Success, 'user', $user->id, [
            'unix_username' => $unixUsername,
            'role' => $role->value,
        ]);

        return $user;
    }

    public function changeRole(AuditActor $actor, User $user, UserRole $role): void
    {
        if ($user->role === UserRole::Admin && $role !== UserRole::Admin) {
            $this->assertNotLastAdmin($user);
        }

        $previous = $user->role;
        $user->update(['role' => $role]);

        $this->audit->record($actor, 'user.role', AuditResult::Success, 'user', $user->id, [
            'from' => $previous->value,
            'to' => $role->value,
        ]);
    }

    public function deactivate(AuditActor $actor, User $user): void
    {
        if ($actor->userId === $user->id) {
            throw new DomainException('No et pots desactivar a tu mateix.');
        }

        if ($user->role === UserRole::Admin) {
            $this->assertNotLastAdmin($user);
        }

        $user->update(['active' => false]);

        $this->audit->record($actor, 'user.deactivate', AuditResult::Success, 'user', $user->id, [
            'unix_username' => $user->unix_username,
        ]);
    }

    public function find(string $unixUsername): User
    {
        return User::query()->where('unix_username', $unixUsername)->first()
            ?? throw new DomainException(sprintf("L'usuari «%s» no existeix.", $unixUsername));
    }

    public function assertValidUsername(string $unixUsername): void
    {
        if (preg_match(self::UNIX_USERNAME, $unixUsername) !== 1) {
            throw new DomainException(sprintf("«%s» no és un nom d'usuari Unix vàlid.", $unixUsername));
        }
    }

    private function assertNotLastAdmin(User $user): void
    {
        $otherAdmins = User::query()
            ->where('role', UserRole::Admin->value)
            ->where('active', true)
            ->whereKeyNot($user->id)
            ->count();

        if ($otherAdmins === 0) {
            throw new DomainException("No es pot treure l'últim admin actiu.");
        }
    }
}
