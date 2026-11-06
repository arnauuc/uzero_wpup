<?php

declare(strict_types=1);

namespace App\Console\Commands\Cleanup;

use App\Console\ChangesState;
use App\Console\WpupCommand;
use App\Domain\Audit\AuditActor;
use App\Domain\Cleanup\CleanupResult;
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
        {--dry-run : Mostra què es faria sense fer-ho}
        {--json : Sortida en JSON, per a scripts}';

    protected $description = 'Neteja programada segons la taula de retenció (ADR-0009)';

    public function handle(CleanupService $service): int
    {
        $dryRun = $this->option('dry-run') === true;
        $results = $service->run(AuditActor::system('neteja de retenció'), CarbonImmutable::now(), $dryRun);
        $failed = array_filter($results, static fn (CleanupResult $r): bool => $r->failed()) !== [];

        if ($this->option('json') === true) {
            $this->line((string) json_encode(['dry_run' => $dryRun, 'tasks' => array_map(static fn (CleanupResult $r): array => $r->toArray(), $results)], WpupCommand::JSON_FLAGS));

            return $failed ? self::FAILURE : self::SUCCESS;
        }
        foreach ($results as $result) {
            $this->line(sprintf('%s: %d (%d bytes)%s', $result->task, $result->count, $result->bytes, $result->note === null ? '' : ' - '.$result->note));
            foreach ($result->failures as $failure) {
                $this->error("{$result->task}: {$failure}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
