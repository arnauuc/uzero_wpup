<?php

declare(strict_types=1);

use App\Domain\Scheduling\SchedulerAction;
use App\Domain\Scheduling\SchedulerDecision;
use App\Domain\Scheduling\UpdateDurationEstimator;
use App\Domain\Scheduling\UpdateScheduler;
use App\Enums\AuditChannel;
use App\Enums\RunStatus;
use App\Enums\RunStepKind;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Enums\ScheduledWindowStatus;
use App\Enums\SiteBlockSource;
use App\Enums\UpdateStrategy;
use App\Models\AuditLogEntry;
use App\Models\Run;
use App\Models\RunStep;
use App\Models\ScheduledWindow;
use App\Models\Site;
use App\Models\SiteSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/*
 * The scheduler of ADR-0006 (UZEROWPUP-129) on a simulated clock. Runs are
 * queued but never executed (fake queue): the tests finish them by hand.
 * 2026-11-02 is a Monday.
 */

beforeEach(function (): void {
    Queue::fake();
    config([
        'wpup.updates.window.enforced' => true,
        'wpup.updates.window.minimum_minutes' => 15,
        'wpup.sites.default_window' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '01:00', 'end' => '06:00'],
        'wpup.scheduler.estimate.history_runs' => 5,
        'wpup.scheduler.estimate.default_minutes' => ['in_place' => 10, 'staging_first' => 30],
        'wpup.scheduler.outlook.snapshot_max_age_hours' => 24,
        'wpup.planning.quarantine_days' => 0,
    ]);
    // The fixtures (history, inventories) are dated from here.
    schedAt('2026-11-02 00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function schedAt(string $moment): CarbonImmutable
{
    $now = CarbonImmutable::parse($moment, 'Europe/Madrid');
    CarbonImmutable::setTestNow($now);

    return $now;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function schedSite(string $name, array $attributes = []): Site
{
    return Site::factory()->create($attributes + ['name' => $name, 'auto_update' => true]);
}

/**
 * @return list<SchedulerDecision>
 */
function schedTick(string $moment, bool $dryRun = false): array
{
    return app(UpdateScheduler::class)->tick(schedAt($moment), $dryRun);
}

/**
 * @param  list<SchedulerDecision>  $decisions
 * @return list<string>
 */
function schedActions(array $decisions, SchedulerAction $action): array
{
    return array_values(array_map(static fn (SchedulerDecision $d): string => (string) $d->site, array_filter($decisions, static fn (SchedulerDecision $d): bool => $d->action === $action)));
}

/**
 * Ends the queued update run of the site as $status, now.
 */
function schedFinish(string $site, RunStatus $status = RunStatus::Succeeded): Run
{
    $run = Run::query()->whereHas('site', static fn ($q) => $q->where('name', $site))->where('status', RunStatus::Queued->value)->sole();
    $run->forceFill(['status' => $status, 'started_at' => CarbonImmutable::now()->subMinutes(5), 'finished_at' => CarbonImmutable::now(), 'result_summary' => $status === RunStatus::Succeeded ? null : ['reason' => 'Ha fallat la prova de fum.']])->save();

    return $run;
}

/**
 * A finished update of the site that went past the plan, $minutes long, ended $daysAgo days ago.
 */
function schedHistory(Site $site, int $minutes, int $daysAgo = 1, UpdateStrategy $strategy = UpdateStrategy::InPlace, RunStatus $status = RunStatus::Succeeded): Run
{
    $end = CarbonImmutable::now()->subDays($daysAgo);
    $run = Run::factory()->create(['site_id' => $site->id, 'type' => RunType::Update, 'strategy' => $strategy, 'status' => $status, 'started_at' => $end->subMinutes($minutes), 'finished_at' => $end]);
    RunStep::factory()->create(['run_id' => $run->id, 'sequence' => 3, 'name' => 'backup', 'kind' => RunStepKind::Forward, 'status' => RunStepStatus::Succeeded]);

    return $run;
}

/**
 * A fresh inventory of the site with these pending updates.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $updates  type, slug, from, to
 */
function schedSnapshot(Site $site, array $updates, int $hoursAgo = 1): void
{
    SiteSnapshot::factory()->create([
        'site_id' => $site->id,
        'core_version' => '6.8.1',
        'plugins' => [],
        'pending_updates' => array_map(static fn (array $u): array => [
            'type' => $u[0], 'slug' => $u[1], 'current_version' => $u[2], 'new_version' => $u[3],
            'requires_php' => null, 'requires_wp' => null, 'requires_wc' => null, 'requires_plugins' => [], 'licensed' => true,
        ], $updates),
        'taken_at' => CarbonImmutable::now()->subHours($hoursAgo),
    ]);
}

it('queues only the active sites with automatic updates whose window is open', function (): void {
    schedSite('alfa');
    schedSite('beta', ['auto_update' => false]);
    schedSite('gamma', ['active' => false]);
    schedSite('delta', ['maintenance_window' => ['days' => [1, 2, 3, 4, 5], 'start' => '10:00', 'end' => '13:00']]);

    $night = schedTick('2026-11-02 01:00');
    expect(schedActions($night, SchedulerAction::Enqueued))->toBe(['alfa'])
        ->and(schedActions($night, SchedulerAction::Launched))->toBe(['alfa']);

    schedFinish('alfa');
    // A day window: Monday morning.
    $day = schedTick('2026-11-02 10:00');
    expect(schedActions($day, SchedulerAction::Enqueued))->toBe(['delta'])
        ->and(schedActions($day, SchedulerAction::Launched))->toBe(['delta'])
        ->and(ScheduledWindow::query()->count())->toBe(2);
});

it('starts one update at a time when several windows overlap, and handles each site once per window', function (): void {
    schedSite('alfa');
    schedSite('beta', ['maintenance_window' => ['days' => [1], 'start' => '00:30', 'end' => '03:00']]);

    $first = schedTick('2026-11-02 01:00');
    expect(schedActions($first, SchedulerAction::Enqueued))->toBe(['alfa', 'beta'])
        ->and(schedActions($first, SchedulerAction::Launched))->toHaveCount(1)
        ->and(Run::query()->count())->toBe(1);

    // The run is queued: nothing else starts.
    $waiting = schedTick('2026-11-02 01:01');
    expect(schedActions($waiting, SchedulerAction::Launched))->toBe([])
        ->and(array_map(static fn (SchedulerDecision $d): SchedulerAction => $d->action, $waiting))->toBe([SchedulerAction::Waiting]);

    // Running is busy too.
    $run = Run::query()->sole();
    $run->forceFill(['status' => RunStatus::Running, 'started_at' => CarbonImmutable::now()])->save();
    expect(schedActions(schedTick('2026-11-02 01:02'), SchedulerAction::Launched))->toBe([]);

    $run->forceFill(['status' => RunStatus::Succeeded, 'finished_at' => CarbonImmutable::now()])->save();
    $next = schedTick('2026-11-02 01:10');
    expect(schedActions($next, SchedulerAction::Settled))->toHaveCount(1)
        ->and(schedActions($next, SchedulerAction::Launched))->toHaveCount(1)
        ->and(Run::query()->count())->toBe(2);

    // Both handled in this window: nothing more until the next one.
    schedFinish((string) Run::query()->latest('id')->firstOrFail()->site?->name);
    $done = schedTick('2026-11-02 01:20');
    expect(schedActions($done, SchedulerAction::Enqueued))->toBe([])
        ->and(schedActions($done, SchedulerAction::Launched))->toBe([])
        ->and(ScheduledWindow::query()->pluck('status')->all())->each->toBe(ScheduledWindowStatus::Succeeded);

    // Tuesday night: alfa's next window.
    expect(schedActions(schedTick('2026-11-03 01:00'), SchedulerAction::Launched))->toBe(['alfa']);
});

it('handles a window that crosses midnight as one occurrence, belonging to the day it starts', function (): void {
    // Saturdays from 23:00 to 02:00 (2026-11-07 is a Saturday).
    schedSite('nit', ['maintenance_window' => ['days' => [6], 'start' => '23:00', 'end' => '02:00']]);

    expect(schedActions(schedTick('2026-11-07 22:59'), SchedulerAction::Enqueued))->toBe([]);
    expect(schedActions(schedTick('2026-11-07 23:00'), SchedulerAction::Launched))->toBe(['nit']);
    schedFinish('nit');

    // Sunday 01:00 still belongs to Saturday's window, already handled.
    expect(schedActions(schedTick('2026-11-08 01:00'), SchedulerAction::Enqueued))->toBe([]);
    // Sunday 23:00 is not one of its days.
    expect(schedActions(schedTick('2026-11-08 23:00'), SchedulerAction::Enqueued))->toBe([]);

    $entry = ScheduledWindow::query()->sole();
    expect($entry->opens_at->setTimezone('Europe/Madrid')->format('Y-m-d H:i'))->toBe('2026-11-07 23:00')
        ->and($entry->closes_at->setTimezone('Europe/Madrid')->format('Y-m-d H:i'))->toBe('2026-11-08 02:00');

    // A Sunday 01:00 tick of the following week finds Saturday's new window.
    expect(schedActions(schedTick('2026-11-15 01:00'), SchedulerAction::Enqueued))->toBe(['nit']);
});

it('orders the queue: pending security, deferred from the previous window, longest without updating, then by name', function (): void {
    $recent = schedSite('a-recent');
    schedHistory($recent, 5, 1);
    $old = schedSite('b-old');
    schedHistory($old, 5, 30);
    schedSite('c-never');
    schedSite('d-never');
    $secure = schedSite('z-security');
    schedHistory($secure, 5, 1);
    // Core x.y.z: a security update (ADR-0005), from a fresh inventory.
    schedSnapshot($secure, [['core', 'wordpress', '6.8.1', '6.8.2']]);
    $deferred = schedSite('y-deferred');
    schedHistory($deferred, 5, 1);
    ScheduledWindow::query()->create([
        'site_id' => $deferred->id, 'opens_at' => CarbonImmutable::parse('2026-11-01 01:00', 'Europe/Madrid'),
        'closes_at' => CarbonImmutable::parse('2026-11-01 06:00', 'Europe/Madrid'), 'status' => ScheduledWindowStatus::Deferred,
    ]);

    $order = [];
    $minute = 0;
    do {
        $launched = schedActions(schedTick(sprintf('2026-11-02 01:%02d', $minute++)), SchedulerAction::Launched);
        foreach ($launched as $site) {
            $order[] = $site;
            schedFinish($site);
        }
    } while ($launched !== [] && $minute < 20);

    expect($order)->toBe(['z-security', 'y-deferred', 'c-never', 'd-never', 'b-old', 'a-recent'])
        ->and(ScheduledWindow::query()->where('site_id', $deferred->id)->latest('opens_at')->firstOrFail()->priority)->toBeTrue();
});

it('skips a site with nothing applicable without spending any time, and starts the next one in the same tick', function (): void {
    $idle = schedSite('a-idle');
    // Fresh inventory, everything up to date.
    schedSnapshot($idle, []);
    $frozen = schedSite('b-frozen');
    schedSnapshot($frozen, [['plugin', 'akismet', '5.3', '5.4']]);
    $frozen->profile()->create(['frozen_components' => ['akismet']]);
    $stale = schedSite('c-stale');
    // An inventory older than the limit says nothing: the run decides.
    schedSnapshot($stale, [], 30);

    $decisions = schedTick('2026-11-02 01:00');

    expect(schedActions($decisions, SchedulerAction::Skipped))->toBe(['a-idle', 'b-frozen'])
        ->and(schedActions($decisions, SchedulerAction::Launched))->toBe(['c-stale'])
        ->and(Run::query()->count())->toBe(1);
    $skip = ScheduledWindow::query()->where('site_id', $idle->id)->sole();
    expect($skip->status)->toBe(ScheduledWindowStatus::Skipped)
        ->and($skip->reason)->toStartWith('Res a aplicar');
});

it('estimates the duration from the history of the strategy and leaves a site that would not fit for its next window, with priority', function (): void {
    $slow = schedSite('a-slow');
    // Median of 40, 50 and 90 minutes: 50. A staging run of 5 minutes does not count.
    schedHistory($slow, 40, 2);
    schedHistory($slow, 90, 3);
    schedHistory($slow, 50, 4, status: RunStatus::RolledBack);
    schedHistory($slow, 5, 5, UpdateStrategy::StagingFirst);
    // Updated more recently than a-slow: its turn comes after it.
    schedHistory(schedSite('b-new'), 10, 1, UpdateStrategy::StagingFirst);

    expect(app(UpdateDurationEstimator::class)->estimateSeconds($slow, UpdateStrategy::InPlace))->toBe(50 * 60)
        ->and(app(UpdateDurationEstimator::class)->estimateSeconds(Site::query()->where('name', 'b-new')->sole(), UpdateStrategy::InPlace))->toBe(10 * 60);

    // 05:15: 45 minutes left. a-slow (50) does not fit; b-new (in place: the default, 10) does.
    $decisions = schedTick('2026-11-02 05:15');
    expect(schedActions($decisions, SchedulerAction::Deferred))->toBe(['a-slow'])
        ->and(schedActions($decisions, SchedulerAction::Launched))->toBe(['b-new']);
    $deferred = ScheduledWindow::query()->where('site_id', $slow->id)->sole();
    expect($deferred->status)->toBe(ScheduledWindowStatus::Deferred)
        ->and($deferred->reason)->toContain('cal uns 50 min i en queden 45');
    schedFinish('b-new');

    // Next night it goes first, ahead of a site that never updated.
    schedSite('0-never');
    $next = schedTick('2026-11-03 01:00');
    expect(schedActions($next, SchedulerAction::Launched))->toBe(['a-slow'])
        ->and(ScheduledWindow::query()->where('site_id', $slow->id)->latest('opens_at')->firstOrFail()->priority)->toBeTrue();
});

it('never starts with less than the minimum of the window left, whatever the estimate', function (): void {
    schedSite('alfa');
    config(['wpup.scheduler.estimate.default_minutes' => ['in_place' => 1]]);

    $decisions = schedTick('2026-11-02 05:50');

    expect(schedActions($decisions, SchedulerAction::Deferred))->toBe(['alfa'])
        ->and(Run::query()->count())->toBe(0);
});

it('defers the sites still queued when their window closes', function (): void {
    schedSite('alfa', ['maintenance_window' => ['days' => [1], 'start' => '01:00', 'end' => '02:00']]);
    schedSite('beta', ['maintenance_window' => ['days' => [1], 'start' => '01:00', 'end' => '02:00']]);
    schedTick('2026-11-02 01:00');
    // alfa's run never finishes inside the window: beta never gets its turn.
    $decisions = schedTick('2026-11-02 02:00');

    expect(schedActions($decisions, SchedulerAction::Deferred))->toBe(['beta'])
        ->and(ScheduledWindow::query()->where('status', ScheduledWindowStatus::Deferred->value)->sole()->reason)->toContain("s'ha tancat");
});

it('lets manual runs that may start go first and is not held back by one that waits for its window', function (): void {
    $manual = schedSite('manual', ['maintenance_window' => ['days' => [1], 'start' => '10:00', 'end' => '13:00'], 'auto_update' => false]);
    $waiting = Run::factory()->create(['site_id' => $manual->id, 'type' => RunType::Update, 'actor' => 'oper1', 'channel' => AuditChannel::Cli]);
    schedSite('alfa');

    // The manual run waits for 10:00: the scheduler goes on.
    expect(schedActions(schedTick('2026-11-02 01:00'), SchedulerAction::Launched))->toBe(['alfa']);
    schedFinish('alfa');

    // A manual run that may start now is ahead in the queue.
    schedSite('beta', ['maintenance_window' => ['days' => [1], 'start' => '10:30', 'end' => '13:00']]);
    $decisions = schedTick('2026-11-02 10:30');
    expect(schedActions($decisions, SchedulerAction::Enqueued))->toBe(['beta'])
        ->and(schedActions($decisions, SchedulerAction::Launched))->toBe([])
        ->and(collect($decisions)->firstWhere('action', SchedulerAction::Waiting)?->reason)->toContain("#{$waiting->id}");
});

it('queues its runs as the system scheduler, with the site strategy and never with version policy overrides', function (): void {
    schedSite('alfa');
    schedTick('2026-11-02 01:00');

    $run = Run::query()->sole();
    $entry = ScheduledWindow::query()->sole();
    expect($run->actor)->toBe('sistema (scheduler)')
        ->and($run->user_id)->toBeNull()
        ->and($run->channel)->toBe(AuditChannel::System)
        ->and($run->type)->toBe(RunType::Update)
        ->and($run->strategy)->toBe(UpdateStrategy::InPlace)
        ->and($run->flags)->toBe(['scheduled' => true, 'scheduled_window_id' => $entry->id])
        ->and($entry->status)->toBe(ScheduledWindowStatus::Launched)
        ->and($entry->run_id)->toBe($run->id)
        ->and(AuditLogEntry::query()->where('action', 'run.queued')->sole()->actor)->toBe('sistema (scheduler)');
});

it('skips the sites it cannot update automatically and cancels the ones that stopped being eligible', function (): void {
    schedSite('a-staging', ['strategy' => UpdateStrategy::StagingFirst]);
    schedSite('b-operator', ['blocked_reason' => 'revisió manual', 'blocked_by' => SiteBlockSource::Operator]);
    $off = schedSite('c-off');
    // Something else is running: everyone waits in the queue.
    $busy = Run::factory()->create(['site_id' => schedSite('z-busy', ['auto_update' => false])->id, 'status' => RunStatus::Running]);
    schedTick('2026-11-02 01:00');
    $off->forceFill(['auto_update' => false])->save();
    $busy->forceFill(['status' => RunStatus::Succeeded, 'finished_at' => CarbonImmutable::now()])->save();

    $decisions = schedTick('2026-11-02 01:05');

    expect(schedActions($decisions, SchedulerAction::Skipped))->toBe(['a-staging', 'b-operator'])
        ->and(schedActions($decisions, SchedulerAction::Cancelled))->toBe(['c-off'])
        ->and(Run::query()->count())->toBe(1);
});

it('does not try a failed site again in the same window', function (): void {
    schedSite('alfa');
    schedTick('2026-11-02 01:00');
    schedFinish('alfa', RunStatus::RolledBack);

    $decisions = schedTick('2026-11-02 01:05');
    $entry = ScheduledWindow::query()->sole();

    expect(schedActions($decisions, SchedulerAction::Settled))->toBe(['alfa'])
        ->and(schedActions($decisions, SchedulerAction::Launched))->toBe([])
        ->and($entry->status)->toBe(ScheduledWindowStatus::Failed)
        ->and($entry->reason)->toBe('Ha fallat la prova de fum.')
        ->and(schedActions(schedTick('2026-11-02 03:00'), SchedulerAction::Launched))->toBe([])
        ->and(schedActions(schedTick('2026-11-03 01:00'), SchedulerAction::Launched))->toBe(['alfa']);
});

it('writes nothing in a dry run', function (): void {
    schedSite('alfa');

    $decisions = schedTick('2026-11-02 01:00', dryRun: true);

    expect(schedActions($decisions, SchedulerAction::Launched))->toBe(['alfa'])
        ->and(ScheduledWindow::query()->count())->toBe(0)
        ->and(Run::query()->count())->toBe(0);
    command('engine:schedule-updates', ['--dry-run' => true])->assertSuccessful();
    expect(Run::query()->count())->toBe(0);
});
