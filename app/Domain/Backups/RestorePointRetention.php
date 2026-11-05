<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Locks\LockedException;
use App\Domain\Locks\LockLevel;
use App\Domain\Locks\SiteLockManager;
use App\Domain\Runs\Exceptions\RunException;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\AuditResult;
use App\Enums\BackupTargetType;
use App\Enums\RunStatus;
use App\Models\Backup;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Run;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Retention of restore points (ADR-0009, AGENTS.md rule 3): decides which
 * points are purged and purges them at every destination (site-local and
 * external, same retention).
 *
 * A point is the live `backups` rows of one site under one key directory. The
 * rules, in precedence order:
 *
 * 1. Never purged, whatever its age (RetentionHold, first match reported): the
 *    point just made; any point of a site whose lock is held by someone else;
 *    a protected point; a point of an unfinished run; of a rolled back or
 *    failed run nobody has reviewed; of a run an unfinished run was launched
 *    from (parent_run_id); and, on a site with an unfinished run, its newest
 *    point verified on the site (what a rollback restores).
 * 2. Purged when expired (every row's expires_at has passed).
 * 3. Then, while the site still has more than `backups.max_per_site` points,
 *    the oldest one not held is purged. Held points still count.
 *
 * Purging takes the site lock (a busy site is left for the next purge) and
 * recomputes the site's plan under it, so it only deletes what is still due
 * then. A file that cannot be deleted keeps its row live (logged, redacted)
 * and the next purge retries it. Every purge leaves one `backups.pruned`
 * entry in audit_log.
 */
final readonly class RestorePointRetention
{
    public function __construct(
        private BackupTargetRegistry $registry,
        private SiteLockManager $locks,
        private AuditLogger $audit,
        private SecretRedactor $redactor,
    ) {}

    /**
     * What a purge would do now, for $sites (every site when null).
     *
     * @param  list<int>|null  $siteIds
     */
    public function plan(?array $siteIds = null): RetentionPlan
    {
        return $this->compute($siteIds, checkLocks: true);
    }

    /**
     * Purges the points of $plan that are still due once each site is locked.
     */
    public function prune(RetentionPlan $plan, AuditActor $actor, string $trigger): PruneResult
    {
        /** @var array<int, list<string>> $directories */
        $directories = [];
        $sites = [];
        foreach ($plan->purges as $purge) {
            $directories[$purge->site->id][] = $purge->point->directory;
            $sites[$purge->site->id] = $purge->site;
        }

        $purged = $failures = $busy = [];
        foreach ($sites as $siteId => $site) {
            try {
                $lease = $this->locks->acquire($site, $actor->name);
            } catch (LockedException) {
                $busy[] = $site;

                continue;
            }

            try {
                [$sitePurged, $siteFailures] = $this->purgeSite($site, $actor, $directories[$siteId], null, []);
                array_push($purged, ...$sitePurged);
                array_push($failures, ...$siteFailures);
            } finally {
                $lease->release();
            }
        }

        $result = new PruneResult($purged, $failures, $busy);
        $this->record($actor, $trigger, $result, null);

        return $result;
    }

    /**
     * The site's plan recomputed now (its lock is held), limited to
     * $directories when given, purged.
     *
     * @param  list<string>|null  $directories
     * @param  array<int, BackupTarget>  $targets
     * @return array{list<PlannedPurge>, list<PruneFailure>}
     */
    private function purgeSite(Site $site, AuditActor $actor, ?array $directories, ?string $exclude, array $targets): array
    {
        $purged = $failures = [];

        foreach ($this->compute([$site->id], checkLocks: false, exclude: $exclude)->purges as $purge) {
            if ($directories !== null && ! in_array($purge->point->directory, $directories, true)) {
                continue;
            }

            $pointFailures = $this->purgePoint($site, $purge->point, $actor, $targets);
            if ($pointFailures === []) {
                $purged[] = $purge;
            }
            array_push($failures, ...$pointFailures);
        }

        return [$purged, $failures];
    }

    /**
     * Deletes every file of $point at its destination, then marks its row deleted.
     *
     * @param  array<int, BackupTarget>  $targets  open destinations, filled as they are opened
     * @return list<PruneFailure>
     */
    private function purgePoint(Site $site, StoredRestorePoint $point, AuditActor $actor, array &$targets): array
    {
        $failures = [];

        foreach ($point->rows as $row) {
            try {
                $target = $targets[$row->backup_target_id] ??= $this->target($row, $site, $actor);
                $target->delete($row->path);
                $row->update(['deleted_at' => CarbonImmutable::now()]);
            } catch (RunException $e) {
                // The run itself was stopped (timeout, claim lost): never swallowed.
                throw $e;
            } catch (Throwable $e) {
                $reason = $this->redactor->redact($e->getMessage());
                $destination = $row->backupTarget->name ?? "#{$row->backup_target_id}";
                Log::warning('Could not delete a file of a restore point; its row stays and the next purge retries it.', [
                    'site_id' => $site->id, 'key' => $row->path, 'destination' => $destination, 'exception' => $e::class, 'reason' => $reason,
                ]);
                $failures[] = new PruneFailure($site->id, $point->directory, $row->path, $destination,
                    $e instanceof BackupTargetException ? $reason : 'error inesperat (vegeu el log)');
            }
        }

        return $failures;
    }

    /**
     * @throws BackupTargetException
     */
    private function target(Backup $row, Site $site, AuditActor $actor): BackupTarget
    {
        $record = $row->backupTarget ?? throw new BackupTargetException("La destinació {$row->backup_target_id} no existeix.");

        return $this->registry->for($record, $site, $actor);
    }

    /**
     * @param  list<int>|null  $siteIds
     */
    private function compute(?array $siteIds, bool $checkLocks, ?string $exclude = null): RetentionPlan
    {
        $maxPerSite = config()->integer('wpup.backups.max_per_site');
        if ($maxPerSite < 1) {
            throw new LogicException('wpup.backups.max_per_site must be at least 1.');
        }

        $now = CarbonImmutable::now();
        $rows = Backup::query()
            ->with(['site', 'backupTarget'])
            ->whereNull('deleted_at')
            ->when($siteIds !== null, static fn ($q) => $q->whereIn('site_id', $siteIds ?? []))
            ->orderBy('id')
            ->get();

        $localTargetIds = array_values(BackupTargetRecord::query()->where('type', BackupTargetType::SiteLocal)->get(['id'])->map(static fn (BackupTargetRecord $r): int => $r->id)->all());
        $runs = Run::query()->whereIn('id', $rows->pluck('run_id')->filter()->unique()->values())->get(['id', 'status', 'reviewed_at'])->keyBy('id');
        $unfinished = Run::query()
            ->whereNotIn('status', array_map(static fn (RunStatus $s): string => $s->value, array_filter(RunStatus::cases(), static fn (RunStatus $s): bool => $s->isTerminal())))
            ->whereIn('site_id', $rows->pluck('site_id')->unique()->values())
            ->get(['id', 'site_id', 'parent_run_id']);
        $context = new RetentionContext(
            $now,
            $localTargetIds,
            $runs,
            array_values(array_unique(array_map(static fn (Run $r): int => (int) $r->site_id, $unfinished->all()))),
            array_values(array_filter(array_map(static fn (Run $r): ?int => $r->parent_run_id, $unfinished->all()))),
        );

        $purges = $held = [];
        foreach ($rows->groupBy('site_id') as $siteRows) {
            /** @var Collection<int, Backup> $siteRows */
            $site = $siteRows->first()?->site;
            if ($site === null) {
                continue;
            }
            $busy = $checkLocks && $this->locks->holder(LockLevel::Site, $site->id) !== null;
            [$sitePurges, $siteHeld] = $this->planSite($site, $this->points($site->id, $siteRows), $context, $maxPerSite, $busy, $exclude);
            array_push($purges, ...$sitePurges);
            array_push($held, ...$siteHeld);
        }

        return new RetentionPlan($purges, $held);
    }

    /**
     * @param  list<StoredRestorePoint>  $points  oldest first
     * @return array{list<PlannedPurge>, list<RetainedPoint>}
     */
    private function planSite(Site $site, array $points, RetentionContext $context, int $maxPerSite, bool $busy, ?string $exclude): array
    {
        $newestVerified = null;
        if (in_array($site->id, $context->sitesWithUnfinishedRun, true)) {
            foreach ($points as $point) {
                if ($point->isVerifiedLocally($context->localTargetIds)) {
                    $newestVerified = $point->directory;
                }
            }
        }

        $purges = $held = [];
        $holds = [];
        foreach ($points as $point) {
            $holds[$point->directory] = $this->hold($point, $context, $busy, $exclude, $newestVerified);
        }

        $remaining = count($points);
        $done = [];
        foreach ($points as $point) {
            if (! $point->isExpired($context->now)) {
                continue;
            }
            $done[$point->directory] = true;
            $hold = $holds[$point->directory];
            if ($hold === null) {
                $purges[] = new PlannedPurge($site, $point, PurgeReason::Expired);
                $remaining--;
            } else {
                $held[] = new RetainedPoint($site, $point, PurgeReason::Expired, $hold);
            }
        }

        foreach ($points as $point) {
            if ($remaining <= $maxPerSite) {
                break;
            }
            $hold = $holds[$point->directory];
            if ($hold === null && ! isset($done[$point->directory])) {
                $purges[] = new PlannedPurge($site, $point, PurgeReason::OverCap);
                $remaining--;
            } elseif ($hold !== null && ! isset($done[$point->directory])) {
                $held[] = new RetainedPoint($site, $point, PurgeReason::OverCap, $hold);
            }
        }

        return [$purges, $held];
    }

    private function hold(StoredRestorePoint $point, RetentionContext $context, bool $busy, ?string $exclude, ?string $newestVerified): ?RetentionHold
    {
        $runId = $point->runId();
        $run = $runId === null ? null : $context->runs->get($runId);

        return match (true) {
            $point->directory === $exclude => RetentionHold::JustCreated,
            $busy => RetentionHold::SiteBusy,
            $point->isProtected() => RetentionHold::Protected,
            // A row whose run is gone (or not readable) is treated as unfinished: never guessed.
            $runId !== null && ($run === null || ! $run->status->isTerminal()) => RetentionHold::RunInProgress,
            $run !== null && $run->reviewed_at === null && ProtectIncidentRestorePoints::isIncident($run) => RetentionHold::IncidentNotReviewed,
            $runId !== null && in_array($runId, $context->parentRunIds, true) => RetentionHold::NeededByRun,
            $point->directory === $newestVerified => RetentionHold::NewestOfActiveSite,
            default => null,
        };
    }

    /**
     * @param  Collection<int, Backup>  $rows
     * @return list<StoredRestorePoint> oldest first
     */
    private function points(int $siteId, Collection $rows): array
    {
        $byDirectory = [];
        foreach ($rows as $row) {
            $byDirectory[dirname($row->path)][] = $row;
        }

        $points = [];
        foreach ($byDirectory as $directory => $directoryRows) {
            $points[] = new StoredRestorePoint($siteId, (string) $directory, $directoryRows);
        }
        usort($points, static fn (StoredRestorePoint $a, StoredRestorePoint $b): int => [$a->createdAt()->getTimestamp(), $a->firstId()] <=> [$b->createdAt()->getTimestamp(), $b->firstId()]);

        return $points;
    }

    private function record(AuditActor $actor, string $trigger, PruneResult $result, ?int $siteId): void
    {
        if ($result->purged === [] && $result->failures === []) {
            return;
        }

        $this->audit->record($actor, 'backups.pruned', $result->failed() ? AuditResult::Failure : AuditResult::Success, $siteId === null ? null : 'site', $siteId, [
            'trigger' => $trigger,
            'purged' => array_map(static fn (PlannedPurge $p): array => [
                'site_id' => $p->site->id,
                'directory' => $p->point->directory,
                'run_id' => $p->point->runId(),
                'reason' => $p->reason->value,
                'backup_ids' => $p->point->backupIds(),
            ], $result->purged),
            'failures' => array_map(static fn (PruneFailure $f): array => [
                'site_id' => $f->siteId, 'directory' => $f->directory, 'key' => $f->key, 'destination' => $f->destination, 'reason' => $f->reason,
            ], $result->failures),
            'busy_site_ids' => array_map(static fn (Site $s): int => $s->id, $result->busySites),
        ]);
    }
}
