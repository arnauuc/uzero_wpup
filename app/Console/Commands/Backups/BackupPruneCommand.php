<?php

declare(strict_types=1);

namespace App\Console\Commands\Backups;

use App\Console\ChangesState;
use App\Console\Concerns\AppliesRetention;
use App\Console\WpupCommand;
use App\Domain\Backups\RetentionPlan;
use App\Domain\Identity\Ability;
use App\Domain\Sites\SiteSelectionException;
use App\Domain\Sites\SiteSelector;
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
        '.self::TARGETS.'
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
        // Without a selection, every site (inactive ones too: their points also expire).
        $names = $this->argument('sites');
        $names = is_array($names) ? array_values(array_filter($names, is_string(...))) : [];
        $tags = $this->option('tag');
        $tags = is_array($tags) ? array_values(array_filter($tags, is_string(...))) : [];
        $all = $this->option('all') === true;
        $siteIds = null;
        if ($names !== [] || $tags !== [] || $all) {
            try {
                $siteIds = array_values(app(SiteSelector::class)->select($names, $tags, $all)->map(static fn (Site $s): int => $s->id)->all());
            } catch (SiteSelectionException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
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
