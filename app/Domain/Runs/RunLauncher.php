<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Enums\AuditResult;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\UpdateStrategy;
use App\Jobs\ExecuteRun;
use App\Models\Run;
use App\Models\Site;

/**
 * Queues runs (one per site, or one fleet-level run with no site) and hands
 * them to the engine. Commands return the run ids at once (UZEROWPUP-24).
 */
final readonly class RunLauncher
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  list<Site|null>  $sites  null = fleet-level run
     * @param  array<string, mixed>  $flags
     * @return list<Run>
     */
    public function launch(RunType $type, array $sites, AuditActor $actor, array $flags = [], ?UpdateStrategy $strategy = null): array
    {
        $runs = [];
        foreach ($sites as $site) {
            $run = Run::query()->create([
                'site_id' => $site?->id,
                'user_id' => $actor->userId,
                'actor' => $actor->name,
                'channel' => $actor->channel,
                'type' => $type,
                'strategy' => $strategy,
                'status' => RunStatus::Queued,
                'flags' => $flags,
            ]);
            $this->audit->record($actor, 'run.queued', AuditResult::Success, 'run', $run->id, [
                'type' => $type->value,
                'site' => $site?->name,
                'flags' => $flags,
            ]);
            $runs[] = $run;
        }

        // Dispatched after every row exists, so a sync queue sees the whole batch.
        foreach ($runs as $run) {
            ExecuteRun::dispatch($run->id);
        }

        return $runs;
    }
}
