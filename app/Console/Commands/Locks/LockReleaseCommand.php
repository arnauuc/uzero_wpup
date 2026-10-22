<?php

declare(strict_types=1);

namespace App\Console\Commands\Locks;

use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\Locks\LockLevel;
use App\Domain\Locks\SiteLockManager;
use App\Enums\AuditResult;
use App\Models\Site;

/**
 * Forced release of a lock (admin only). For when a run is known to be dead and
 * the operator does not want to wait for the TTL.
 */
final class LockReleaseCommand extends WpupCommand
{
    protected $signature = 'lock:release
        {site? : Nom del lloc}
        {--account= : Id del compte d\'hosting, en lloc d\'un lloc}
        {--dry-run : Mostra què es faria sense fer-ho}';

    protected $description = 'Allibera per la força un bloqueig (només admin)';

    protected function ability(): Ability
    {
        return Ability::ManageSites;
    }

    protected function perform(): int
    {
        $locks = app(SiteLockManager::class);
        $account = $this->stringOption('account');

        if ($account !== null) {
            if (! ctype_digit($account)) {
                $this->error("L'id del compte d'hosting ha de ser numèric.");

                return self::FAILURE;
            }
            $level = LockLevel::HostingAccount;
            $objectId = (int) $account;
            $label = "compte d'hosting #{$objectId}";
        } else {
            $site = Site::query()->where('name', $this->stringArgument('site'))->first();

            if ($site === null) {
                $this->error('Indica un lloc existent o --account=<id>.');

                return self::FAILURE;
            }
            $level = LockLevel::Site;
            $objectId = $site->id;
            $label = "lloc «{$site->name}»";
        }

        $holder = $locks->holder($level, $objectId);

        if ($holder === null) {
            $this->info("El {$label} no està bloquejat.");

            return self::SUCCESS;
        }

        $this->warn("Bloquejat per {$holder->describe()}.");

        if ($this->isDryRun()) {
            $this->info("[dry-run] S'alliberaria el bloqueig del {$label}.");

            return self::SUCCESS;
        }

        if (! $this->confirm("Alliberar-lo? L'execució que el té pot quedar a mitges.", false)) {
            return self::FAILURE;
        }

        $locks->forceRelease($level, $objectId);
        $this->audit->record($this->actor(), 'lock.release', AuditResult::Success, $level->value, $objectId, [
            'holder' => $holder->actor,
            'run_id' => $holder->runId,
        ]);
        $this->info("Bloqueig del {$label} alliberat.");

        return self::SUCCESS;
    }
}
