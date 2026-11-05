<?php

declare(strict_types=1);

namespace App\Console\Commands\Backups;

use App\Console\ChangesState;
use App\Console\Concerns\AppliesRetention;
use App\Domain\Audit\AuditActor;
use Illuminate\Console\Command;

/**
 * Scheduler-only daily purge of restore points (ADR-0009), not reachable
 * through the `wpup` binary. It purges without asking: the plan only holds
 * what the retention allows, and the purge is audited as `backups.pruned`.
 * Operators use `wpup backup:prune`.
 */
final class PruneRestorePointsCommand extends Command implements ChangesState
{
    use AppliesRetention;

    protected $signature = 'engine:prune-restore-points
        {--dry-run : Mostra què es faria sense fer-ho}
        {--json : Sortida en JSON, per a scripts}';

    protected $description = 'Neteja programada dels punts de restauració caducats o que superen el màxim per lloc';

    public function handle(): int
    {
        return $this->applyRetention(null, AuditActor::system('retenció de punts de restauració'), 'schedule',
            $this->option('dry-run') === true, $this->option('json') === true, static fn (): bool => true);
    }
}
