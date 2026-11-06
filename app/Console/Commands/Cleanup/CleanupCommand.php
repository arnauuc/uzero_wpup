<?php

declare(strict_types=1);

namespace App\Console\Commands\Cleanup;

use App\Console\ChangesState;
use App\Console\WpupCommand;
use App\Domain\Cleanup\CleanupResult;
use App\Domain\Cleanup\CleanupService;
use App\Domain\Identity\Ability;
use Carbon\CarbonImmutable;

/**
 * Applies the retention table of ADR-0009 §4 now (CleanupService). Admin only:
 * it destroys data, so it asks for confirmation (or --force). Each class
 * leaves its own summary in audit_log. The scheduler runs `engine:cleanup`.
 */
final class CleanupCommand extends WpupCommand implements ChangesState
{
    protected $signature = 'cleanup
        {--force : No demana confirmació (scripts)}
        '.self::DRY_RUN.'
        '.self::JSON;

    protected $description = 'Aplica la taula de retenció: sortida completa dels passos, artefactes, versions de paquets i captures';

    protected function ability(): Ability
    {
        return Ability::RunCleanup;
    }

    protected function perform(): int
    {
        $dryRun = $this->isDryRun();
        $force = $this->option('force') === true;
        if ($this->wantsJson() && ! $dryRun && ! $force) {
            $this->error('Amb --json, cal --dry-run o --force: no es pot demanar confirmació.');

            return self::FAILURE;
        }
        $service = app(CleanupService::class);
        $now = CarbonImmutable::now();

        if (! $dryRun && ! $force) {
            // Like backup:prune: show what would go before asking.
            $preview = $service->run($this->actor(), $now, true);
            $this->printResults($preview, true);
            if (! $this->confirm('Aplicar la neteja de retenció? No es pot desfer.', false)) {
                $this->warn("No s'ha esborrat res.");

                return self::FAILURE;
            }
        }

        $results = $service->run($this->actor(), $now, $dryRun);
        if ($this->wantsJson()) {
            $this->emitJson(['dry_run' => $dryRun, 'tasks' => array_map(static fn (CleanupResult $r): array => $r->toArray(), $results)]);
        } else {
            $this->printResults($results, $dryRun);
        }

        return array_filter($results, static fn (CleanupResult $r): bool => $r->failed()) === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<CleanupResult>  $results
     */
    private function printResults(array $results, bool $dryRun): void
    {
        $this->table(['Classe', $dryRun ? 'Es netejarien' : 'Netejats', 'Bytes', 'Nota'], array_map(static fn (CleanupResult $r): array => [
            $r->task, $r->count, $r->bytes, $r->note ?? '',
        ], $results));
        foreach ($results as $result) {
            foreach ($result->failures as $failure) {
                $this->error("{$result->task}: {$failure}");
            }
        }
    }
}
