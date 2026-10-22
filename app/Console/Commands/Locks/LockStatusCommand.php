<?php

declare(strict_types=1);

namespace App\Console\Commands\Locks;

use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\Locks\LockInfo;
use App\Domain\Locks\LockLevel;
use App\Domain\Locks\SiteLockManager;
use App\Models\HostingAccount;
use App\Models\Site;

final class LockStatusCommand extends WpupCommand
{
    protected $signature = 'lock:status';

    protected $description = 'Mostra els bloquejos actius (llocs i comptes d\'hosting)';

    protected function ability(): Ability
    {
        return Ability::ViewReports;
    }

    protected function perform(): int
    {
        $locks = app(SiteLockManager::class)->status();

        if ($locks === []) {
            $this->info('No hi ha cap bloqueig actiu.');

            return self::SUCCESS;
        }

        $this->table(
            ['Nivell', 'Objecte', 'Qui', 'Execució', 'Des de', 'Caduca en'],
            array_map(fn (LockInfo $lock): array => [
                $lock->level->label(),
                $this->objectName($lock),
                $lock->holder->actor,
                $lock->holder->runId === null ? '—' : '#'.$lock->holder->runId,
                $lock->holder->acquiredAt->format('Y-m-d H:i:s'),
                $lock->secondsLeft.' s',
            ], $locks),
        );

        return self::SUCCESS;
    }

    private function objectName(LockInfo $lock): string
    {
        if ($lock->level === LockLevel::Site) {
            $name = Site::query()->whereKey($lock->objectId)->value('name');

            return is_string($name) ? $name : '#'.$lock->objectId;
        }

        $account = HostingAccount::query()->with('hostingServer')->find($lock->objectId);

        return $account === null ? '#'.$lock->objectId : $account->system_user.'@'.($account->hostingServer->name ?? '?');
    }
}
