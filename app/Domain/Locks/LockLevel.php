<?php

declare(strict_types=1);

namespace App\Domain\Locks;

enum LockLevel: string
{
    case Site = 'site';
    case HostingAccount = 'hosting_account';

    public function label(): string
    {
        return match ($this) {
            self::Site => 'lloc',
            self::HostingAccount => "compte d'hosting",
        };
    }
}
