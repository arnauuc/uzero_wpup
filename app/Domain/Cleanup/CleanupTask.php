<?php

declare(strict_types=1);

namespace App\Domain\Cleanup;

use App\Domain\Audit\AuditActor;
use Carbon\CarbonImmutable;

/**
 * One row of the ADR-0009 retention table. A task purges on its own and
 * leaves its own summary in audit_log (nothing on a dry run or when there was
 * nothing to do); CleanupService isolates failures between tasks.
 */
interface CleanupTask
{
    public function name(): string;

    public function run(AuditActor $actor, CarbonImmutable $now, bool $dryRun): CleanupResult;
}
