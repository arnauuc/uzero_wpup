<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Backups\RestorePointRetention;
use App\Domain\Locks\LockLevel;
use App\Domain\Locks\SiteLockManager;
use App\Enums\AuditResult;
use App\Enums\RunStatus;
use App\Models\RunArtifact;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Throwable;

/**
 * Retention of package artifacts (ADR-0009): kept while the run's restore
 * point exists (a backups row of the run not purged), and at least
 * min_retention_days. Like restore points (RestorePointRetention), never
 * while their run is unfinished or interrupted, is a rolled back or failed
 * run nobody reviewed (isIncident), is the parent of an unfinished run (a
 * rollback, a restore), or its site or hosting account is locked.
 *
 * The daily cleanup job (UZEROWPUP-134) calls purge(): each artifact is
 * purged on its own (a failure is logged and counted, the rest go on) and a
 * purge that had anything to do leaves one `artifacts.purged` summary in
 * audit_log, failures included.
 */
final readonly class ArtifactRetention
{
    public function __construct(
        private ArtifactStore $store,
        private AuditLogger $audit,
        private SiteLockManager $locks,
        private ArtifactSettings $settings,
    ) {}

    /**
     * @return LazyCollection<int, RunArtifact>
     */
    public function purgeable(CarbonImmutable $now): LazyCollection
    {
        $active = array_map(static fn (RunStatus $s): string => $s->value, array_filter(RunStatus::cases(), static fn (RunStatus $s): bool => ! $s->isTerminal()));

        return RunArtifact::query()
            ->with('run.site')
            ->whereNull('purged_at')
            ->where('created_at', '<=', $now->subDays($this->settings->minRetentionDays))
            ->whereNotExists(static fn (QueryBuilder $query): QueryBuilder => $query->selectRaw('1')->from('backups')
                ->whereColumn('backups.run_id', 'run_artifacts.run_id')
                ->whereNull('backups.deleted_at'))
            ->whereNotExists(static fn (QueryBuilder $query): QueryBuilder => $query->selectRaw('1')->from('runs as children')
                ->whereColumn('children.parent_run_id', 'run_artifacts.run_id')
                ->whereIn('children.status', $active))
            ->lazyById()
            ->filter(fn (RunArtifact $row): bool => ! $this->held($row));
    }

    public function purge(AuditActor $actor, CarbonImmutable $now, bool $dryRun = false): ArtifactPurgeResult
    {
        $count = 0;
        $bytes = 0;
        $runs = [];
        $failures = [];
        try {
            foreach ($this->purgeable($now) as $row) {
                if (! $dryRun) {
                    try {
                        $this->store->purge($row, $now);
                    } catch (Throwable $e) {
                        $message = $e instanceof ArtifactException ? $e->getMessage() : "No s'ha pogut purgar l'artefacte {$row->id}.";
                        Log::warning("Artifact {$row->id} could not be purged: ".$e::class.' '.$message);
                        $failures[] = $message;

                        continue;
                    }
                }
                $count++;
                $bytes += $row->size_bytes;
                $runs[$row->run_id] = true;
            }
        } finally {
            if (! $dryRun && ($count > 0 || $failures !== [])) {
                $this->audit->record($actor, 'artifacts.purged', $failures === [] ? AuditResult::Success : AuditResult::Failure, null, null, [
                    'purged' => $count,
                    'bytes' => $bytes,
                    'runs' => array_keys($runs),
                    'failed' => count($failures),
                    'failures' => $failures,
                    'min_retention_days' => $this->settings->minRetentionDays,
                ]);
            }
        }

        return new ArtifactPurgeResult($count, $bytes, $failures, $dryRun);
    }

    private function held(RunArtifact $row): bool
    {
        $run = $row->run;
        if ($run === null) {
            // Never guessed (as RestorePointRetention does).
            return true;
        }
        $site = $run->site;

        return ! $run->status->isTerminal()
            || ($run->reviewed_at === null && RestorePointRetention::isIncident($run))
            || ($site !== null && ($this->locks->holder(LockLevel::Site, $site->id) !== null
                || $this->locks->holder(LockLevel::HostingAccount, $site->hosting_account_id) !== null));
    }
}
