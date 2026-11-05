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
use Closure;
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
 *    point just made; any point of a site whose lock, or whose hosting
 *    account's lock, is held by someone else; a point protected by hand; a
 *    point of an unfinished run; of a rolled back or failed run nobody has
 *    reviewed (isIncident(), read from the run: the only source of truth);
 *    of a run an unfinished run was launched from (parent_run_id) or restores
 *    from (a `backup:restore` run, flags.restore_point); and, on a
 *    site with an unfinished run, its newest point verified on the site (what
 *    a rollback restores).
 * 2. Purged when expired (every row's expires_at has passed).
 * 3. Then, while the site still has more than `backups.max_per_site` points,
 *    the oldest one not held is purged. Held points still count.
 *
 * Purging takes the site lock (and its hosting account's; a busy site is left
 * for the next purge), recomputes the site's plan under it, so it only
 * deletes what is still due then, and renews the lock before every file: a
 * lost lock stops the site at once. A file that cannot be deleted keeps its
 * row live (logged, redacted) and the next purge retries it; a destination
 * that cannot be opened is skipped for the rest of the purge. Each purged site
 * leaves one `backups.pruned` entry in audit_log, written even when the purge
 * stops halfway.
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
     * ADR-0009: the point of a reverted update, or of a run with an incident,
     * is held until an operator reviews the run (`run:ack`). Read from the run
     * itself (status and reviewed_at), conservatively: any run that ended
     * rolled back or failed (it may have changed the site before failing, or
     * its rollback may have failed).
     */
    public static function isIncident(Run $run): bool
    {
        return in_array($run->status, [RunStatus::RolledBack, RunStatus::Failed], true);
    }

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
     * Each site is audited right after it is purged.
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

        $results = [];
        foreach ($sites as $siteId => $site) {
            try {
                // The site and its hosting account, as a run takes them.
                $lease = $this->locks->acquire($site, $actor->name);
            } catch (LockedException) {
                $results[] = new PruneResult(busySites: [$site]);

                continue;
            }

            try {
                $results[] = $this->purgeSite($site, $actor, $trigger, $directories[$siteId], null, [], $lease->refresh(...));
            } finally {
                $lease->release();
            }
        }

        return PruneResult::merge($results);
    }

    /**
     * Applies the retention to $site right after a point was made (ADR-0009:
     * making a third point purges the oldest one not held). Never the point
     * just made. Best effort: every failure, taking the lock included, is
     * logged and never thrown. The only exception is a RunException, the
     * engine's signal that the run itself was stopped (timeout, claim lost),
     * which no step may swallow.
     *
     * @param  bool  $callerHoldsLock  the run making the point holds (and renews) the site lock
     * @param  array<int, BackupTarget>  $targets  destinations already open, by backup_targets.id
     */
    public function enforceAfterCreate(Site $site, string $directory, AuditActor $actor, bool $callerHoldsLock, array $targets = []): PruneResult
    {
        $lease = null;

        try {
            if (! $callerHoldsLock) {
                try {
                    $lease = $this->locks->acquire($site, $actor->name);
                } catch (LockedException) {
                    return new PruneResult(busySites: [$site]);
                }
            }

            return $this->purgeSite($site, $actor, 'restore_point_created', null, $directory, $targets, $lease === null ? static fn (): bool => true : $lease->refresh(...));
        } catch (RunException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Could not apply the retention after making a restore point.', [
                'site_id' => $site->id, 'exception' => $e::class, 'reason' => $this->redactor->redact($e->getMessage()),
            ]);

            return new PruneResult;
        } finally {
            try {
                $lease?->release();
            } catch (Throwable $e) {
                Log::error('Could not release the site lock after the retention.', ['site_id' => $site->id, 'reason' => $this->redactor->redact($e->getMessage())]);
            }
        }
    }

    /**
     * The site's plan recomputed now (its lock is held), limited to
     * $directories when given, purged. The lock is renewed ($keepLock) before
     * every file: if it is lost, nothing else is deleted. Whatever was
     * deleted is audited, even if an exception stops the purge.
     *
     * @param  list<string>|null  $directories
     * @param  array<int, BackupTarget>  $targets  destinations already open, by backup_targets.id
     * @param  Closure(): bool  $keepLock
     */
    private function purgeSite(Site $site, AuditActor $actor, string $trigger, ?array $directories, ?string $exclude, array $targets, Closure $keepLock): PruneResult
    {
        $purged = $failures = $deleted = [];
        /** @var array<int, string> $unavailable destinations that could not be opened, with the reason */
        $unavailable = [];
        $lockLost = false;

        try {
            foreach ($this->compute([$site->id], checkLocks: false, exclude: $exclude)->purges as $purge) {
                if ($directories !== null && ! in_array($purge->point->directory, $directories, true)) {
                    continue;
                }

                $pointFailures = $this->purgePoint($site, $purge->point, $actor, $targets, $unavailable, $keepLock, $deleted, $lockLost);
                if ($lockLost) {
                    Log::warning('Lost the site lock while purging restore points; the purge of the site stops.', ['site_id' => $site->id]);

                    break;
                }
                if ($pointFailures === []) {
                    $purged[] = $purge;
                }
                array_push($failures, ...$pointFailures);
            }
        } finally {
            $result = new PruneResult($purged, $failures, lockLostSites: $lockLost ? [$site] : []);
            $this->record($actor, $trigger, $site, $result, $deleted);
        }

        return $result;
    }

    /**
     * Deletes every file of $point at its destination, then marks its row deleted.
     *
     * @param  array<int, BackupTarget>  $targets  open destinations, filled as they are opened
     * @param  array<int, string>  $unavailable  destinations that failed to open: their files are skipped
     * @param  Closure(): bool  $keepLock
     * @param  list<int>  $deleted  ids of the rows deleted, appended
     * @return list<PruneFailure>
     */
    private function purgePoint(Site $site, StoredRestorePoint $point, AuditActor $actor, array &$targets, array &$unavailable, Closure $keepLock, array &$deleted, bool &$lockLost): array
    {
        $failures = [];

        foreach ($point->rows as $row) {
            $destination = $row->backupTarget->name ?? "#{$row->backup_target_id}";
            if (isset($unavailable[$row->backup_target_id])) {
                $failures[] = new PruneFailure($site->id, $point->directory, $row->path, $destination, $unavailable[$row->backup_target_id]);

                continue;
            }
            if (! $keepLock()) {
                $lockLost = true;

                return $failures;
            }

            try {
                $target = $targets[$row->backup_target_id] ?? null;
                if ($target === null) {
                    try {
                        $target = $targets[$row->backup_target_id] = $this->target($row, $site, $actor);
                    } catch (BackupTargetException $e) {
                        $unavailable[$row->backup_target_id] = $reason = $this->redactor->redact($e->getMessage());
                        Log::warning('Could not open a destination to purge restore points; its files are skipped.', [
                            'site_id' => $site->id, 'destination' => $destination, 'reason' => $reason,
                        ]);
                        $failures[] = new PruneFailure($site->id, $point->directory, $row->path, $destination, $reason);

                        continue;
                    }
                }
                $target->delete($row->path);
                $row->update(['deleted_at' => CarbonImmutable::now()]);
                $deleted[] = $row->id;
            } catch (RunException $e) {
                // The run itself was stopped (timeout, claim lost): never swallowed.
                throw $e;
            } catch (Throwable $e) {
                $reason = $this->redactor->redact($e->getMessage());
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
     * Only to delete existing copies: an inactive destination, or one no
     * longer linked to the site, is still opened (BackupTargetRegistry::forDeletion()).
     *
     * @throws BackupTargetException
     */
    private function target(Backup $row, Site $site, AuditActor $actor): BackupTarget
    {
        $record = $row->backupTarget ?? throw new BackupTargetException("La destinació {$row->backup_target_id} no existeix.");

        return $this->registry->forDeletion($record, $site, $actor);
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
            ->get(['id', 'site_id', 'parent_run_id', 'flags']);
        $context = new RetentionContext(
            $now,
            $localTargetIds,
            $runs,
            array_values(array_unique(array_map(static fn (Run $r): int => (int) $r->site_id, $unfinished->all()))),
            array_values(array_filter(array_map(static fn (Run $r): ?int => $r->parent_run_id, $unfinished->all()))),
            array_values(array_filter(array_map(static fn (Run $r): ?string => is_string($r->flags[RestoreRequest::POINT_FLAG] ?? null) ? $r->flags[RestoreRequest::POINT_FLAG] : null, $unfinished->all()))),
        );

        $purges = $held = [];
        foreach ($rows->groupBy('site_id') as $siteRows) {
            /** @var Collection<int, Backup> $siteRows */
            $site = $siteRows->first()?->site;
            if ($site === null) {
                continue;
            }
            // Busy as acquire() sees it: the site or its hosting account.
            $busy = $checkLocks && ($this->locks->holder(LockLevel::Site, $site->id) !== null
                || $this->locks->holder(LockLevel::HostingAccount, $site->hosting_account_id) !== null);
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
            $run !== null && $run->reviewed_at === null && self::isIncident($run) => RetentionHold::IncidentNotReviewed,
            ($runId !== null && in_array($runId, $context->parentRunIds, true))
                || in_array($point->directory, $context->neededDirectories, true) => RetentionHold::NeededByRun,
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

    /**
     * One `backups.pruned` entry per site, with every row it deleted (also of
     * points left half purged).
     *
     * @param  list<int>  $deleted
     */
    private function record(AuditActor $actor, string $trigger, Site $site, PruneResult $result, array $deleted): void
    {
        if ($deleted === [] && $result->failures === [] && $result->lockLostSites === []) {
            return;
        }

        $this->audit->record($actor, 'backups.pruned', $result->failed() ? AuditResult::Failure : AuditResult::Success, 'site', $site->id, [
            'trigger' => $trigger,
            'purged' => array_map(static fn (PlannedPurge $p): array => [
                'directory' => $p->point->directory,
                'run_id' => $p->point->runId(),
                'reason' => $p->reason->value,
                'backup_ids' => $p->point->backupIds(),
            ], $result->purged),
            'deleted_backup_ids' => $deleted,
            'failures' => array_map(static fn (PruneFailure $f): array => [
                'directory' => $f->directory, 'key' => $f->key, 'destination' => $f->destination, 'reason' => $f->reason,
            ], $result->failures),
            'lock_lost' => $result->lockLostSites !== [],
        ]);
    }
}
