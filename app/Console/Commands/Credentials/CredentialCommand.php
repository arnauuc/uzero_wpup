<?php

declare(strict_types=1);

namespace App\Console\Commands\Credentials;

use App\Console\ChangesState;
use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Models\HostingAccount;

/**
 * Base of the key and secret commands: admin only, CLI only (never web,
 * ADR-0008), with the hosting account given as <server> <system user>.
 */
abstract class CredentialCommand extends WpupCommand implements ChangesState
{
    protected function ability(): Ability
    {
        return Ability::ManageCredentials;
    }

    protected function account(): ?HostingAccount
    {
        $server = $this->stringArgument('server');
        $user = $this->stringArgument('account');
        $account = HostingAccount::query()
            ->where('system_user', $user)
            ->whereHas('hostingServer', static fn ($q) => $q->where('name', $server))
            ->first();

        if ($account === null) {
            $this->error("No hi ha cap compte «{$user}» al servidor «{$server}».");
        }

        return $account;
    }
}
