<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Enums\UserRole;
use App\Models\User;

/**
 * What an operator may do. The same mapping backs the CLI and, later, the web
 * panel gates (ADR-0008: one policy for both channels).
 */
enum Ability: string
{
    /** Add and edit sites, hosting accounts and profiles. */
    case ManageSites = 'manage-sites';

    /** Store or rotate credentials and keys (CLI only, never web). */
    case ManageCredentials = 'manage-credentials';

    /** Add users, change roles, deactivate. */
    case ManageUsers = 'manage-users';

    /** Launch inventory, plan, update, backup, verify and rollback runs. */
    case RunOperations = 'run-operations';

    /** Read runs, reports and site data. */
    case ViewReports = 'view-reports';

    public function allows(User $user): bool
    {
        if (! $user->active) {
            return false;
        }

        return match ($this) {
            self::ManageSites, self::ManageCredentials, self::ManageUsers => $user->role === UserRole::Admin,
            self::RunOperations, self::ViewReports => true,
        };
    }
}
