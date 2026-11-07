<?php

declare(strict_types=1);

use App\Domain\Runs\Concurrency\ConcurrencyLimitReached;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunEngine;
use App\Domain\Runs\StepOutcome;
use App\Enums\AuditChannel;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Run;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Runs\ScriptedStep;

/*
 * ADR-0006 §2 (UZEROWPUP-129): manual and scheduled runs share one queue, in
 * the order they were queued; only an admin's --now goes ahead.
 */

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    app(PipelineResolver::class)->register(RunType::Update, static fn (): Pipeline => new Pipeline([
        new ScriptedStep('a', static fn (): StepOutcome => StepOutcome::succeeded('fet')),
    ]));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function queuedUpdate(array $attributes = []): Run
{
    return Run::factory()->create($attributes + ['site_id' => Site::factory()->create()->id, 'type' => RunType::Update]);
}

it('starts the runs in the order they were queued, scheduled or manual', function (): void {
    $scheduled = queuedUpdate();
    $manual = queuedUpdate(['actor' => 'oper1', 'channel' => AuditChannel::Cli]);

    expect(fn () => app(RunEngine::class)->execute($manual))->toThrow(ConcurrencyLimitReached::class);
    expect($manual->refresh()->status)->toBe(RunStatus::Queued);

    expect(app(RunEngine::class)->execute($scheduled)->status)->toBe(RunStatus::Succeeded)
        ->and(app(RunEngine::class)->execute($manual)->status)->toBe(RunStatus::Succeeded);
});

it('lets an admin\'s --now go ahead of the queue', function (): void {
    queuedUpdate();
    $now = queuedUpdate(['actor' => 'admin1', 'channel' => AuditChannel::Cli, 'flags' => ['now' => true]]);

    expect(app(RunEngine::class)->execute($now)->status)->toBe(RunStatus::Succeeded);
});

it('is not held back by an earlier run waiting for its window, nor by one lost long ago', function (): void {
    config(['wpup.updates.window.enforced' => true, 'wpup.sites.default_window' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '01:00', 'end' => '06:00']]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 01:00', 'Europe/Madrid'));
    queuedUpdate(['site_id' => Site::factory()->create(['maintenance_window' => ['days' => [1], 'start' => '10:00', 'end' => '13:00']])->id]);
    queuedUpdate(['queued_at' => CarbonImmutable::now()->subHours(30)]);
    $next = queuedUpdate();

    expect(app(RunEngine::class)->execute($next)->status)->toBe(RunStatus::Succeeded);
});

it('also queues behind earlier runs of other types that take the global slot', function (): void {
    Run::factory()->create(['site_id' => Site::factory()->create()->id, 'type' => RunType::Backup]);
    $update = queuedUpdate();

    expect(fn () => app(RunEngine::class)->execute($update))->toThrow(ConcurrencyLimitReached::class);
});
