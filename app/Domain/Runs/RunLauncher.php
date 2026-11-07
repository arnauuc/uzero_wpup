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
use App\Models\HostingAccount;
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
     * One run that targets a hosting account instead of a site (key rotation).
     *
     * @param  array<string, mixed>  $flags
     */
    public function launchForAccount(RunType $type, HostingAccount $account, AuditActor $actor, array $flags = []): Run
    {
        $run = $this->queue($type, null, $account, $actor, $flags, null, null);
        ExecuteRun::dispatch($run->id);

        return $run;
    }

    /**
     * @param  list<Site|null>  $sites  null = fleet-level run
     * @param  array<string, mixed>  $flags
     * @param  Run|null  $parent  the run each one is launched from (a manual rollback's update, UZEROWPUP-61)
     * @return list<Run>
     */
    public function launch(RunType $type, array $sites, AuditActor $actor, array $flags = [], ?UpdateStrategy $strategy = null, ?Run $parent = null): array
    {
        $runs = [];
        foreach ($sites as $site) {
            $runs[] = $this->queue($type, $site, null, $actor, $flags, $strategy, $parent);
        }

        // Dispatched after every row exists, so a sync queue sees the whole batch.
        foreach ($runs as $run) {
            ExecuteRun::dispatch($run->id);
        }

        return $runs;
    }

    /**
     * Creates and audits one queued run of a site WITHOUT handing it to the
     * engine, so the caller can record it in the same transaction and
     * dispatch() it after the commit (the scheduler, UZEROWPUP-129).
     *
     * @param  array<string, mixed>  $flags
     */
    public function prepare(RunType $type, Site $site, AuditActor $actor, array $flags = [], ?UpdateStrategy $strategy = null): Run
    {
        return $this->queue($type, $site, null, $actor, $flags, $strategy, null);
    }

    public function dispatch(Run $run): void
    {
        ExecuteRun::dispatch($run->id);
    }

    /**
     * Creates one queued run and audits it; the caller dispatches it.
     *
     * @param  array<string, mixed>  $flags
     */
    private function queue(RunType $type, ?Site $site, ?HostingAccount $account, AuditActor $actor, array $flags, ?UpdateStrategy $strategy, ?Run $parent): Run
    {
        $run = Run::query()->create([
            'site_id' => $site?->id,
            'parent_run_id' => $parent?->id,
            'hosting_account_id' => $account?->id,
            'user_id' => $actor->userId,
            'actor' => $actor->name,
            'channel' => $actor->channel,
            'type' => $type,
            'strategy' => $strategy,
            'status' => RunStatus::Queued,
            'flags' => $flags,
        ]);
        $context = ['type' => $type->value, 'site' => $site?->name, 'flags' => $flags] + ($parent !== null ? ['parent_run_id' => $parent->id] : []);
        if ($account !== null) {
            $context = ['type' => $type->value, 'hosting_account' => $account->id, 'flags' => $flags];
        }
        $this->audit->record($actor, 'run.queued', AuditResult::Success, 'run', $run->id, $context);

        return $run;
    }
}
