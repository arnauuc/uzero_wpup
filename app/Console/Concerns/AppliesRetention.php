<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupListing;
use App\Domain\Backups\PlannedPurge;
use App\Domain\Backups\PruneFailure;
use App\Domain\Backups\PruneResult;
use App\Domain\Backups\RestorePointRetention;
use App\Domain\Backups\RetainedPoint;
use App\Domain\Backups\RetentionPlan;
use App\Domain\Backups\RetentionReport;
use App\Models\Site;
use Closure;
use Illuminate\Console\Command;

/**
 * The flow shared by `backup:prune` (operators) and the scheduled
 * `engine:prune-restore-points`: show the plan, stop on --dry-run, ask
 * ($confirm) and purge. Exits with an error when a file could not be deleted
 * or a site's lock was lost halfway.
 *
 * @mixin Command
 */
trait AppliesRetention
{
    /**
     * One JSON document on stdout (WpupCommand::emitJson()).
     *
     * @param  array<mixed>  $data
     */
    abstract protected function emitJson(array $data): void;

    /**
     * @param  list<int>|null  $siteIds
     * @param  Closure(RetentionPlan): bool  $confirm
     */
    protected function applyRetention(?array $siteIds, AuditActor $actor, string $trigger, bool $dryRun, bool $json, Closure $confirm): int
    {
        $retention = app(RestorePointRetention::class);
        $plan = $retention->plan($siteIds);

        if (! $json) {
            $this->printRetentionPlan($plan, $dryRun);
        }
        if ($dryRun || $plan->purges === []) {
            if ($json) {
                $this->emitJson(RetentionReport::document($plan, null, $dryRun));
            }

            return Command::SUCCESS;
        }

        if (! $confirm($plan)) {
            $this->warn("No s'ha esborrat res.");

            return Command::FAILURE;
        }

        $result = $retention->prune($plan, $actor, $trigger);
        if ($json) {
            $this->emitJson(RetentionReport::document($plan, $result, false));
        } else {
            $this->printPruneResult($result);
        }

        return $result->failed() ? Command::FAILURE : Command::SUCCESS;
    }

    private function printRetentionPlan(RetentionPlan $plan, bool $dryRun): void
    {
        if ($plan->purges === []) {
            $this->line('No hi ha cap punt de restauració per esborrar.');
        } else {
            $this->line(($dryRun ? '[dry-run] ' : '').'Punts de restauració que '.($dryRun ? "s'esborrarien" : "s'esborraran").', a totes les destinacions:');
            $this->table(['Lloc', 'Punt', 'Execució', 'Creat', 'Fitxers', 'Motiu'], array_map(static fn (PlannedPurge $p): array => [
                $p->site->name,
                $p->point->directory,
                $p->point->runId() === null ? '—' : '#'.$p->point->runId(),
                $p->point->createdAt()->format(BackupListing::dateFormat()),
                count($p->point->rows),
                $p->reason->label(),
            ], $plan->purges));
        }

        if ($plan->held !== []) {
            $this->line('Es conserven, encara que tocaria esborrar-los:');
            $this->table(['Lloc', 'Punt', 'Execució', 'Tocaria', 'Es conserva perquè'], array_map(static fn (RetainedPoint $p): array => [
                $p->site->name,
                $p->point->directory,
                $p->point->runId() === null ? '—' : '#'.$p->point->runId(),
                $p->wouldBe->label(),
                $p->hold->label(),
            ], $plan->held));
        }
    }

    private function printPruneResult(PruneResult $result): void
    {
        $this->info(sprintf('Punts de restauració esborrats: %d.', count($result->purged)));
        foreach ($result->busySites as $site) {
            /** @var Site $site */
            $this->warn("El lloc «{$site->name}» està bloquejat per una altra operació: es deixa per a la propera neteja.");
        }
        foreach ($result->lockLostSites as $site) {
            /** @var Site $site */
            $this->error("S'ha perdut el bloqueig del lloc «{$site->name}» a mitja neteja: s'ha aturat sense esborrar res més.");
        }
        foreach ($result->failures as $failure) {
            /** @var PruneFailure $failure */
            $this->error("No s'ha pogut esborrar «{$failure->key}» de «{$failure->destination}»: {$failure->reason}. Es tornarà a provar a la propera neteja.");
        }
    }
}
