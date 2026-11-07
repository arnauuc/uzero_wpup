<?php

declare(strict_types=1);

namespace App\Domain\Cleanup;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Planning\PackageVersions;
use App\Enums\AuditResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * First sightings of package versions that no inventory has offered for
 * wpup.planning.package_versions_retention_days (PackageVersions::purge).
 */
final readonly class PackageVersionCleanup implements CleanupTask
{
    public const string TASK = 'package_versions';

    public function __construct(private PackageVersions $versions, private AuditLogger $audit) {}

    public function name(): string
    {
        return self::TASK;
    }

    public function run(AuditActor $actor, CarbonImmutable $now, bool $dryRun): CleanupResult
    {
        $failures = [];
        $count = 0;
        try {
            $count = $dryRun ? $this->versions->expired($now) : $this->versions->purge($now);
        } catch (Throwable $e) {
            Log::warning('Package versions cleanup failed: '.$e::class);
            $failures[] = "No s'han pogut netejar les versions de paquets.";
        }
        if (! $dryRun && ($count > 0 || $failures !== [])) {
            $this->audit->record($actor, 'cleanup.package_versions', $failures === [] ? AuditResult::Success : AuditResult::Failure, null, null, [
                'versions' => $count,
                'failures' => $failures,
            ]);
        }

        return new CleanupResult(self::TASK, $count, 0, $dryRun, null, $failures);
    }
}
