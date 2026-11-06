<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Enums\AuditResult;
use App\Models\Backup;
use App\Models\RunArtifact;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Retention of package artifacts (ADR-0009): kept while the run's restore
 * point exists (a backups row of the run not purged), and at least
 * min_retention_days. The daily cleanup job (UZEROWPUP-134) calls purge();
 * each purge that is not a dry run leaves one `artifacts.purged` summary in
 * audit_log, failures included.
 */
final readonly class ArtifactRetention
{
    public function __construct(
        private ArtifactStore $store,
        private AuditLogger $audit,
        private ArtifactSettings $settings,
    ) {}

    /**
     * @return Collection<int, RunArtifact>
     */
    public function purgeable(CarbonImmutable $now): Collection
    {
        return RunArtifact::query()
            ->whereNull('purged_at')
            ->where('created_at', '<=', $now->subDays($this->settings->minRetentionDays))
            ->whereNotExists(static fn (QueryBuilder $query): QueryBuilder => $query->selectRaw('1')
                ->from((new Backup)->getTable())
                ->whereColumn('backups.run_id', 'run_artifacts.run_id')
                ->whereNull('backups.deleted_at'))
            ->orderBy('id')
            ->get();
    }

    public function purge(AuditActor $actor, CarbonImmutable $now, bool $dryRun = false): ArtifactPurgeResult
    {
        $candidates = $this->purgeable($now);
        if ($dryRun) {
            return new ArtifactPurgeResult($candidates->count(), (int) $candidates->sum(static fn (RunArtifact $row): int => $row->size_bytes), [], true);
        }

        $purged = 0;
        $bytes = 0;
        $runs = [];
        $failures = [];
        foreach ($candidates as $row) {
            try {
                $this->store->purge($row, $now);
                $purged++;
                $bytes += $row->size_bytes;
                $runs[$row->run_id] = true;
            } catch (ArtifactException $e) {
                Log::warning("Artifact {$row->id} could not be purged: {$e->getMessage()}");
                $failures[] = $e->getMessage();
            }
        }

        if ($candidates->isNotEmpty()) {
            $this->audit->record($actor, 'artifacts.purged', $failures === [] ? AuditResult::Success : AuditResult::Failure, null, null, [
                'purged' => $purged,
                'bytes' => $bytes,
                'runs' => array_keys($runs),
                'failures' => $failures,
                'min_retention_days' => $this->settings->minRetentionDays,
            ]);
        }

        return new ArtifactPurgeResult($purged, $bytes, $failures, false);
    }
}
