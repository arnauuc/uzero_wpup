<?php

declare(strict_types=1);

use App\Enums\RunStatus;
use App\Enums\ScheduledWindowStatus;
use App\Enums\UserRole;
use App\Models\Run;
use App\Models\ScheduledWindow;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

/*
 * The daily summary of the scheduled updates (ADR-0006 §4, UZEROWPUP-129).
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 07:00', 'Europe/Madrid'));
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('oper1');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, mixed>  $details
 */
function summaryWindow(string $site, ScheduledWindowStatus $status, ?string $reason = null, array $details = [], int $hoursAgo = 3, ?Run $run = null): void
{
    $model = Site::query()->where('name', $site)->first() ?? Site::factory()->create(['name' => $site]);
    $at = CarbonImmutable::now()->subHours($hoursAgo);
    $window = ScheduledWindow::query()->create([
        'site_id' => $model->id, 'opens_at' => $at->subHour(), 'closes_at' => $at->addHours(4),
        'status' => $status, 'reason' => $reason, 'details' => $details, 'run_id' => $run?->id,
    ]);
    $window->forceFill(['updated_at' => $at])->saveQuietly();
}

it('summarises the last 24 hours: updated, skipped with the reason, failed, blocked, deferred, in progress and majors waiting for staging', function (): void {
    $run = Run::factory()->create(['status' => RunStatus::Succeeded]);
    summaryWindow('a-updated', ScheduledWindowStatus::Succeeded, null, ['nothing_to_do' => false, 'updated' => 3, 'majors' => ['plugin:woocommerce 9.9→10.0']], run: $run);
    summaryWindow('b-current', ScheduledWindowStatus::Succeeded, null, ['nothing_to_do' => true, 'updated' => 0]);
    summaryWindow('c-skipped', ScheduledWindowStatus::Skipped, 'Res a aplicar: 0 s\'actualitzarien, 1 s\'ometrien', ['outlook' => ['majors' => ['core:wordpress 6.8.1→6.9.0']]]);
    summaryWindow('d-reverted', ScheduledWindowStatus::Failed, 'Fatal nou.', ['run_status' => 'rolled_back']);
    summaryWindow('e-deferred', ScheduledWindowStatus::Deferred, 'No hi cap: cal uns 50 min i en queden 45 de la finestra.');
    summaryWindow('f-running', ScheduledWindowStatus::Launched);
    // Older than 24 hours: not in the summary.
    summaryWindow('g-old', ScheduledWindowStatus::Succeeded, null, ['nothing_to_do' => false, 'updated' => 1], 30);
    Site::factory()->create(['name' => 'h-blocked', 'auto_update' => true, 'auto_update_blocked_at' => CarbonImmutable::now()->subHours(2), 'auto_update_blocked_reason' => 'Ha fallat 2 finestres seguides.']);

    command('summary', ['--json' => true])->expectsOutputToContain('"majors_waiting_staging"')->assertSuccessful();

    command('summary')
        ->expectsOutputToContain('Actualitzats: 1')
        ->expectsOutputToContain("a-updated (#{$run->id}): 3 components")
        ->expectsOutputToContain('Omesos: 2')
        ->expectsOutputToContain('b-current: res a aplicar')
        ->expectsOutputToContain('c-skipped: Res a aplicar')
        ->expectsOutputToContain("Han fallat o s'han revertit: 1")
        ->expectsOutputToContain('d-reverted: revertit: Fatal nou.')
        ->expectsOutputToContain("Bloquejats per a l'actualització automàtica: 1")
        ->expectsOutputToContain('h-blocked: Ha fallat 2 finestres seguides.')
        ->expectsOutputToContain('Pendents per a la seva finestra següent: 1')
        ->expectsOutputToContain('En curs: 1')
        ->expectsOutputToContain('Majors que esperen staging: 2')
        ->doesntExpectOutputToContain('g-old')
        ->assertSuccessful();

    // A longer period includes the older window.
    command('summary', ['--since' => '2d'])->expectsOutputToContain('Actualitzats: 2')->assertSuccessful();
});

it('refuses a malformed period', function (): void {
    command('summary', ['--since' => 'ahir'])->expectsOutputToContain('--since')->assertFailed();
});

it('logs the daily summary from the scheduler', function (): void {
    summaryWindow('a-updated', ScheduledWindowStatus::Succeeded, null, ['nothing_to_do' => false, 'updated' => 2]);
    Log::shouldReceive('info')->once()->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'Resum de les actualitzacions programades')
        && data_get($context, 'daily_summary.updated.0.site') === 'a-updated');

    command('engine:daily-summary')->expectsOutputToContain('Actualitzats: 1')->assertSuccessful();
});

it('is scheduled every morning at the configured time', function (): void {
    $events = collect(app(Schedule::class)->events());
    $summary = $events->first(static fn ($e): bool => str_contains((string) $e->command, 'engine:daily-summary'));
    $tick = $events->first(static fn ($e): bool => str_contains((string) $e->command, 'engine:schedule-updates'));

    expect($summary?->expression)->toBe('0 7 * * *')
        ->and($tick?->expression)->toBe('* * * * *');
});
