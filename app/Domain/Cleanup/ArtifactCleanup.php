<?php

declare(strict_types=1);

namespace App\Domain\Cleanup;

use App\Domain\Artifacts\ArtifactRetention;
use App\Domain\Audit\AuditActor;
use Carbon\CarbonImmutable;

/**
 * Package artifacts (ADR-0009): kept while the run's restore point exists and
 * at least 30 days. ArtifactRetention (UZEROWPUP-49) decides and writes its own
 * `artifacts.purged` summary to audit_log.
 */
final readonly class ArtifactCleanup implements CleanupTask
{
    public const string TASK = 'artifacts';

    public function __construct(private ArtifactRetention $retention) {}

    public function name(): string
    {
        return self::TASK;
    }

    public function run(AuditActor $actor, CarbonImmutable $now, bool $dryRun): CleanupResult
    {
        $result = $this->retention->purge($actor, $now, $dryRun);

        return new CleanupResult(self::TASK, $result->count, $result->bytes, $dryRun, null, $result->failures);
    }
}
