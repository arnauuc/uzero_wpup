<?php

declare(strict_types=1);

namespace App\Console\Commands\Backups;

use App\Console\ChangesState;
use App\Console\Concerns\AppliesRetention;
use App\Console\WpupCommand;
use App\Domain\Backups\RetentionPlan;
use App\Domain\Identity\Ability;
use App\Models\Site;

/**
 * Applies the retention of restore points now (ADR-0009, RestorePointRetention):
 * expired points and the surplus over the maximum per site, at every
 * destination. Destructive: asks for confirmation, or --force.
 */
final class BackupPruneCommand extends WpupCommand implements ChangesState
{
    use AppliesRetention;

    protected $signature = 'backup:prune
        {--site=* : Només els punts d\'aquest lloc (repetible)}
        {--force : No demana confirmació (scripts)}
        '.self::DRY_RUN.'
        '.self::JSON;

    protected $description = 'Esborra els punts de restauració caducats o que superen el màxim per lloc';

    protected function ability(): Ability
    {
        return Ability::RunOperations;
    }

    protected function perform(): int
    {
        $names = $this->option('site');
        $names = is_array($names) ? array_values(array_filter($names, is_string(...))) : [];
        $siteIds = null;
        if ($names !== []) {
            $sites = Site::query()->whereIn('name', $names)->get(['id', 'name']);
            $missing = array_diff($names, $sites->map(static fn (Site $s): string => $s->name)->all());
            if ($missing !== []) {
                $this->error('No existeix el lloc: '.implode(', ', $missing).'.');

                return self::FAILURE;
            }
            $siteIds = array_values($sites->map(static fn (Site $s): int => $s->id)->all());
        }

        $force = $this->option('force') === true;
        if ($this->wantsJson() && ! $this->isDryRun() && ! $force) {
            $this->error('Amb --json, cal --dry-run o --force: no es pot demanar confirmació.');

            return self::FAILURE;
        }

        return $this->applyRetention($siteIds, $this->actor(), 'backup:prune', $this->isDryRun(), $this->wantsJson(),
            fn (RetentionPlan $plan): bool => $force || $this->confirm(sprintf('Esborrar aquests %d punts de restauració? No es pot desfer.', count($plan->purges)), false));
    }
}
