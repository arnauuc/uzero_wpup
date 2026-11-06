<?php

declare(strict_types=1);

namespace App\Console\Commands\Cleanup;

use App\Console\ChangesState;
use App\Domain\Audit\AuditActor;
use App\Domain\Cleanup\CleanupService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduler-only daily cleanup (ADR-0009 §4), not reachable through the `wpup`
 * binary. It cleans without asking: each class only removes what the
 * retention table allows and audits its own summary. Operators use `wpup cleanup`.
 */
final class EngineCleanup extends Command implements ChangesState
{
    protected $signature = 'engine:cleanup
        {--dry-run : Mostra què es faria sense fer-ho}';

    protected $description = 'Neteja programada segons la taula de retenció (ADR-0009)';

    public function handle(CleanupService $service): int
    {
        $failed = false;
        foreach ($service->run(AuditActor::system('neteja de retenció'), CarbonImmutable::now(), $this->option('dry-run') === true) as $result) {
            $this->line(sprintf('%s: %d (%d bytes)%s', $result->task, $result->count, $result->bytes, $result->note === null ? '' : ' - '.$result->note));
            foreach ($result->failures as $failure) {
                $this->error("{$result->task}: {$failure}");
            }
            $failed = $failed || $result->failed();
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
