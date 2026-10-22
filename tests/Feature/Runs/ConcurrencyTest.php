<?php

declare(strict_types=1);

use App\Domain\Runs\Concurrency\ConcurrencyGate;
use App\Domain\Runs\Concurrency\ConcurrencyLimitReached;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunEngine;
use App\Domain\Runs\StepOutcome;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Jobs\ExecuteRun;
use App\Models\HostingAccount;
use App\Models\Run;
use App\Models\Site;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Runs\ScriptedStep;

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    app(PipelineResolver::class)->register(RunType::Update, static fn (): Pipeline => new Pipeline([
        new ScriptedStep('a', static fn (): StepOutcome => StepOutcome::succeeded('fet')),
    ]));
});

/**
 * @param  array{global: int, per_hosting_server: int, staging: int}  $limits
 */
function gate(array $limits = ['global' => 1, 'per_hosting_server' => 1, 'staging' => 1], int $lease = 60): ConcurrencyGate
{
    $connection = Redis::connection('locks');
    assert($connection instanceof PhpRedisConnection);
    $gate = new ConcurrencyGate($connection, $limits, $lease);
    app()->instance(ConcurrencyGate::class, $gate);

    return $gate;
}

function siteOnServer(?Site $sibling = null): Site
{
    return $sibling === null
        ? Site::factory()->create()
        : Site::factory()->for(HostingAccount::factory()->state(['hosting_server_id' => $sibling->hostingAccount()->firstOrFail()->hosting_server_id]))->create();
}

it('runs one update at a time across the whole fleet by default', function (): void {
    $gate = gate();
    $busy = Run::factory()->create(['site_id' => siteOnServer()->id]);
    $waiting = Run::factory()->create(['site_id' => siteOnServer()->id]);
    $held = $gate->acquire($busy, 'worker-1');

    expect(fn () => app(RunEngine::class)->execute($waiting))->toThrow(ConcurrencyLimitReached::class, 'global')
        ->and($waiting->fresh()?->status)->toBe(RunStatus::Queued);

    $held->release();
    expect(app(RunEngine::class)->execute($waiting)->status)->toBe(RunStatus::Succeeded);
});

it('limits each hosting server separately when the global limit allows more', function (): void {
    $gate = gate(['global' => 3, 'per_hosting_server' => 1, 'staging' => 1]);
    $first = siteOnServer();
    $busy = Run::factory()->create(['site_id' => $first->id]);
    $sameServer = Run::factory()->create(['site_id' => siteOnServer($first)->id]);
    $otherServer = Run::factory()->create(['site_id' => siteOnServer()->id]);
    $gate->acquire($busy, 'worker-1');

    expect(fn () => $gate->acquire($sameServer, 'worker-2'))->toThrow(ConcurrencyLimitReached::class, 'server:')
        ->and($gate->acquire($otherServer, 'worker-3')->keys)->toHaveCount(2);
});

it('lets an admin run flagged now skip only the global queue', function (): void {
    $gate = gate();
    $site = siteOnServer();
    $gate->acquire(Run::factory()->create(['site_id' => siteOnServer()->id]), 'worker-1');

    $urgent = Run::factory()->create(['site_id' => $site->id, 'flags' => ['now' => true]]);
    expect($gate->acquire($urgent, 'worker-2')->keys)->toHaveCount(1);

    $sameServerUrgent = Run::factory()->create(['site_id' => siteOnServer($site)->id, 'flags' => ['now' => true]]);
    expect(fn () => $gate->acquire($sameServerUrgent, 'worker-3'))->toThrow(ConcurrencyLimitReached::class, 'server:');
});

it('limits staging runs on their own level', function (): void {
    $gate = gate(['global' => 5, 'per_hosting_server' => 5, 'staging' => 1]);
    $gate->acquire(Run::factory()->create(['flags' => ['staging' => true]]), 'worker-1');

    expect(fn () => $gate->acquire(Run::factory()->create(['flags' => ['staging' => true]]), 'worker-2'))
        ->toThrow(ConcurrencyLimitReached::class, 'staging')
        ->and($gate->acquire(Run::factory()->create(), 'worker-3')->keys)->not->toContain('wpup:slots:staging');
});

it('frees the slots of a dead worker when its lease expires', function (): void {
    $gate = gate(lease: 1);
    $gate->acquire(Run::factory()->create(), 'dead-worker');

    expect(fn () => $gate->acquire(Run::factory()->create(), 'worker-2'))->toThrow(ConcurrencyLimitReached::class);
    usleep(1_200_000);
    expect($gate->acquire(Run::factory()->create(), 'worker-2')->keys)->not->toBeEmpty();
});

it('keeps a refreshed slot and reports a lost one', function (): void {
    $gate = gate(lease: 1);
    $lease = $gate->acquire(Run::factory()->create(), 'worker-1');

    usleep(600_000);
    expect($lease->refresh())->toBeTrue();
    usleep(600_000);
    expect(fn () => $gate->acquire(Run::factory()->create(), 'worker-2'))->toThrow(ConcurrencyLimitReached::class);

    Redis::connection('locks')->command('flushdb');
    expect($lease->refresh())->toBeFalse();
});

it('puts a waiting job back in the queue without failing it', function (): void {
    $gate = gate();
    $gate->acquire(Run::factory()->create(), 'worker-1');
    $run = Run::factory()->create();

    $job = (new ExecuteRun($run->id))->withFakeQueueInteractions();
    $job->handle(app(RunEngine::class));

    $job->assertReleased(30);
    expect($run->fresh()?->status)->toBe(RunStatus::Queued);
});
