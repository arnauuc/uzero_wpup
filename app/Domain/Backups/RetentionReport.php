<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Runs\ReportVersion;
use App\Models\Backup;
use App\Models\Site;

/**
 * The `backup:prune --json` document (versioned with `wpup.backups.report_version`).
 * Keys and destination names only: no paths of the servers, no secrets.
 */
final class RetentionReport
{
    /**
     * @return array<string, mixed>
     */
    public static function document(RetentionPlan $plan, ?PruneResult $result, bool $dryRun): array
    {
        return [
            'version' => ReportVersion::read('wpup.backups.report_version'),
            'dry_run' => $dryRun,
            'max_per_site' => config()->integer('wpup.backups.max_per_site'),
            'purges' => array_map(static fn (PlannedPurge $p): array => self::point($p->site, $p->point) + ['reason' => $p->reason->value], $plan->purges),
            'held' => array_map(static fn (RetainedPoint $p): array => self::point($p->site, $p->point) + ['would_be' => $p->wouldBe->value, 'hold' => $p->hold->value], $plan->held),
            'result' => $result === null ? null : [
                'purged' => array_map(static fn (PlannedPurge $p): array => ['site' => $p->site->name, 'directory' => $p->point->directory], $result->purged),
                'failures' => array_map(static fn (PruneFailure $f): array => [
                    'site_id' => $f->siteId, 'directory' => $f->directory, 'key' => $f->key, 'destination' => $f->destination, 'reason' => $f->reason,
                ], $result->failures),
                'busy_sites' => array_map(static fn (Site $s): string => $s->name, $result->busySites),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function point(Site $site, StoredRestorePoint $point): array
    {
        return [
            'site' => $site->name,
            'directory' => $point->directory,
            'run_id' => $point->runId(),
            'created_at' => $point->createdAt()->toIso8601String(),
            'backups' => array_map(static fn (Backup $b): array => [
                'id' => $b->id,
                'kind' => $b->kind->value,
                'destination' => $b->backupTarget->name ?? "#{$b->backup_target_id}",
                'key' => $b->path,
                'expires_at' => $b->expires_at?->toIso8601String(),
            ], $point->rows),
        ];
    }
}
