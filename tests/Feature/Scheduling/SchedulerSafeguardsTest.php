<?php

declare(strict_types=1);

use App\Domain\Scheduling\OrphanStagingCleaner;
use App\Domain\Scheduling\SchedulerAction;
use App\Domain\Scheduling\SchedulerDecision;
use App\Domain\Scheduling\SystemToolResources;
use App\Domain\Scheduling\ToolResources;
use App\Domain\Scheduling\UpdateScheduler;
use App\Enums\RunStatus;
use App\Enums\ScheduledWindowStatus;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\Run;
use App\Models\ScheduledWindow;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/*
 * UZEROWPUP-129, ADR-0006 §2 and §3: blocking after failed windows, the tool
 * server's resources and the orphaned stagings, on a simulated clock.
 * 2026-11-02 is a Monday; the default window is every day 01:00–06:00.
 */

final class GuardResources implements ToolResources
{
    public static ?self $current = null;

    public function __construct(public ?int $disk = 100 * 1024 ** 3, public ?int $memory = 16 * 1024 ** 3) {}

    public function freeDiskBytes(string $path): ?int
    {
        return $this->disk;
    }

    public function availableMemoryBytes(): ?int
    {
        return $this->memory;
    }
}

final class GuardStagings implements OrphanStagingCleaner
{
    public static ?self $current = null;

    public int $calls = 0;

    public function clean(): array
    {
        $this->calls++;

        return ['staging-orfe'];
    }
}

beforeEach(function (): void {
    Queue::fake();
    config([
        'wpup.updates.window.enforced' => true,
        'wpup.updates.window.minimum_minutes' => 15,
        'wpup.sites.default_window' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '01:00', 'end' => '06:00'],
        'wpup.scheduler.failures.block_after' => 2,
        'wpup.scheduler.resources.min_free_disk_mb' => 10240,
        'wpup.scheduler.resources.min_free_memory_mb' => 2048,
    ]);
    app()->instance(ToolResources::class, GuardResources::$current = new GuardResources);
    app()->instance(OrphanStagingCleaner::class, GuardStagings::$current = new GuardStagings);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function guardResources(): GuardResources
{
    return GuardResources::$current ?? throw new LogicException('Fake resources not bound.');
}

function guardStagings(): GuardStagings
{
    return GuardStagings::$current ?? throw new LogicException('Fake staging cleaner not bound.');
}

/**
 * @return list<SchedulerDecision>
 */
function guardTick(string $moment): array
{
    $now = CarbonImmutable::parse($moment, 'Europe/Madrid');
    CarbonImmutable::setTestNow($now);

    return app(UpdateScheduler::class)->tick($now);
}

/**
 * @param  list<SchedulerDecision>  $decisions
 * @return list<string>
 */
function guardSites(array $decisions, SchedulerAction $action): array
{
    return array_values(array_map(static fn (SchedulerDecision $d): string => (string) $d->site, array_filter($decisions, static fn (SchedulerDecision $d): bool => $d->action === $action)));
}

/**
 * Starts the site's window at $night 01:00 and ends its run as $status.
 */
function guardNight(string $night, RunStatus $status): void
{
    guardTick("{$night} 01:00");
    $run = Run::query()->where('status', RunStatus::Queued->value)->sole();
    $run->forceFill(['status' => $status, 'started_at' => CarbonImmutable::now(), 'finished_at' => CarbonImmutable::now()->addMinutes(5), 'result_summary' => $status === RunStatus::Succeeded ? null : ['reason' => 'Fatal nou al log de PHP.']])->save();
}

it('blocks a site for automatic updates after 2 consecutive failed windows, with a warning on the run and in audit_log', function (): void {
    $site = Site::factory()->create(['name' => 'fragil', 'auto_update' => true]);

    guardNight('2026-11-02', RunStatus::RolledBack);
    $first = guardTick('2026-11-02 01:10');
    expect(guardSites($first, SchedulerAction::Blocked))->toBe([])
        ->and($site->refresh()->auto_update_blocked_at)->toBeNull();

    guardNight('2026-11-03', RunStatus::Failed);
    $second = guardTick('2026-11-03 01:10');
    $site->refresh();
    $run = Run::query()->latest('id')->firstOrFail();

    expect(guardSites($second, SchedulerAction::Blocked))->toBe(['fragil'])
        ->and($site->auto_update_blocked_at)->not->toBeNull()
        ->and($site->auto_update_blocked_reason)->toContain('2 finestres seguides')
        ->and($site->auto_update)->toBeTrue()
        ->and($run->result_summary['warnings'] ?? null)->toBe([$site->auto_update_blocked_reason])
        ->and($run->result_summary['reason'] ?? null)->toBe('Fatal nou al log de PHP.');
    $audit = AuditLogEntry::query()->where('action', 'site.auto_update_blocked')->sole();
    expect($audit->actor)->toBe('sistema (scheduler)')
        ->and($audit->object_id)->toBe((string) $site->id);

    // Not queued any more, not even in its next window.
    expect(guardSites(guardTick('2026-11-04 01:00'), SchedulerAction::Enqueued))->toBe([]);

    // An operator reviews it and turns automatic updates on again: the block is lifted, audited.
    User::factory()->create(['unix_username' => 'admin1', 'role' => UserRole::Admin]);
    actingAsOperator('admin1');
    command('site:edit', ['name' => 'fragil', '--auto-update' => true, '--no-interaction' => true])->assertSuccessful();
    expect($site->refresh()->auto_update_blocked_at)->toBeNull()
        ->and($site->auto_update_blocked_reason)->toBeNull();
    $changes = (array) (AuditLogEntry::query()->where('action', 'site.edit')->sole()->context['changes'] ?? []);
    expect($changes)->toHaveKey('auto_update_blocked_reason')
        ->and(guardSites(guardTick('2026-11-05 01:00'), SchedulerAction::Launched))->toBe(['fragil']);
});

it('counts only consecutive failed windows: a success resets the count and a skipped window does not break it', function (): void {
    $site = Site::factory()->create(['name' => 'irregular', 'auto_update' => true]);

    guardNight('2026-11-02', RunStatus::Failed);
    guardNight('2026-11-03', RunStatus::Succeeded);
    guardNight('2026-11-04', RunStatus::Failed);
    guardTick('2026-11-04 01:10');
    expect($site->refresh()->auto_update_blocked_at)->toBeNull();

    // A window where it was skipped (nothing to do) sits between two failures.
    ScheduledWindow::query()->create([
        'site_id' => $site->id, 'opens_at' => CarbonImmutable::parse('2026-11-05 01:00', 'Europe/Madrid'),
        'closes_at' => CarbonImmutable::parse('2026-11-05 06:00', 'Europe/Madrid'), 'status' => ScheduledWindowStatus::Skipped,
    ]);
    guardNight('2026-11-06', RunStatus::RolledBack);
    guardTick('2026-11-06 01:10');

    expect($site->refresh()->auto_update_blocked_at)->not->toBeNull();
});

it('cancels a queued window of a site blocked meanwhile', function (): void {
    $site = Site::factory()->create(['name' => 'alfa', 'auto_update' => true]);
    $busy = Run::factory()->create(['site_id' => Site::factory()->create()->id, 'status' => RunStatus::Running]);
    guardTick('2026-11-02 01:00');
    $site->forceFill(['auto_update_blocked_at' => CarbonImmutable::now(), 'auto_update_blocked_reason' => 'dues fallades'])->save();
    $busy->forceFill(['status' => RunStatus::Succeeded, 'finished_at' => CarbonImmutable::now()])->save();

    $decisions = guardTick('2026-11-02 01:05');

    expect(guardSites($decisions, SchedulerAction::Cancelled))->toBe(['alfa'])
        ->and(ScheduledWindow::query()->sole()->reason)->toContain('dues fallades');
});

it('stops and warns once when the tool server is short of disk or memory, and goes on when there is enough', function (): void {
    Site::factory()->create(['name' => 'alfa', 'auto_update' => true]);
    guardResources()->disk = 5 * 1024 ** 3;
    guardResources()->memory = 1024 ** 3;

    $first = guardTick('2026-11-02 01:00');
    $halt = collect($first)->firstWhere('action', SchedulerAction::Halted);
    expect($halt?->reason)->toContain('5120 MB de disc')->toContain('1024 MB de memòria')
        ->and(Run::query()->count())->toBe(0)
        ->and(ScheduledWindow::query()->sole()->status)->toBe(ScheduledWindowStatus::Pending);

    guardTick('2026-11-02 01:01');
    expect(AuditLogEntry::query()->where('action', 'scheduler.halted')->count())->toBe(1);

    guardResources()->disk = 50 * 1024 ** 3;
    guardResources()->memory = 4 * 1024 ** 3;
    expect(guardSites(guardTick('2026-11-02 01:02'), SchedulerAction::Launched))->toBe(['alfa']);
});

it('does not stop when it cannot read the resources', function (): void {
    Site::factory()->create(['name' => 'alfa', 'auto_update' => true]);
    guardResources()->disk = null;
    guardResources()->memory = null;

    expect(guardSites(guardTick('2026-11-02 01:00'), SchedulerAction::Launched))->toBe(['alfa']);
});

it('cleans the orphaned stagings before starting a site, and only then', function (): void {
    guardTick('2026-11-02 01:00');
    expect(guardStagings()->calls)->toBe(0);

    Site::factory()->create(['name' => 'alfa', 'auto_update' => true]);
    guardTick('2026-11-02 01:01');
    expect(guardStagings()->calls)->toBe(1);
});

it('reads the available memory and the free disk of the tool server', function (): void {
    $meminfo = tempnam(sys_get_temp_dir(), 'meminfo');
    file_put_contents((string) $meminfo, "MemTotal:       16384000 kB\nMemFree:          100000 kB\nMemAvailable:    2048000 kB\n");
    $resources = new SystemToolResources((string) $meminfo);

    expect($resources->availableMemoryBytes())->toBe(2048000 * 1024)
        ->and($resources->freeDiskBytes(sys_get_temp_dir()))->toBeInt()->toBeGreaterThan(0)
        ->and((new SystemToolResources('/nonexistent/meminfo'))->availableMemoryBytes())->toBeNull();
    unlink((string) $meminfo);
});
