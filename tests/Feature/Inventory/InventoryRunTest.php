<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Identity\Ability;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\RunEngine;
use App\Enums\AuditChannel;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\Run;
use App\Models\SiteSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeDriver;

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    config(['wpup.runs.heartbeat_seconds' => 1]);
});

it('creates one fresh snapshot per inventory run, linked to the run', function (): void {
    $driver = new FakeDriver('6.8.3');
    app(PipelineResolver::class)->register(RunType::Inventory, static fn (): Pipeline => new Pipeline([
        new InventoryStep(new SiteInventory, static fn () => $driver, app(RunAuthorization::class), 60),
    ]));

    $first = Run::factory()->create(['type' => RunType::Inventory]);
    app(RunEngine::class)->execute($first);

    expect($first->status)->toBe(RunStatus::Succeeded)
        ->and(SiteSnapshot::query()->where('run_id', $first->id)->count())->toBe(1)
        ->and($first->steps()->value('summary'))->toContain('WordPress 6.8.3');

    $driver->coreVersion = '6.9.0';
    $second = Run::factory()->create(['type' => RunType::Inventory, 'site_id' => $first->site_id]);
    app(RunEngine::class)->execute($second);

    $snapshots = SiteSnapshot::query()->where('site_id', $first->site_id)->orderBy('id')->get();
    expect($snapshots)->toHaveCount(2)
        ->and($snapshots->pluck('core_version')->all())->toBe(['6.8.3', '6.9.0'])
        ->and($snapshots->last()?->run_id)->toBe($second->id);
});

it('registers an inventory pipeline in the container', function (): void {
    $run = Run::factory()->create(['type' => RunType::Inventory]);

    expect(app(PipelineResolver::class)->resolve($run)->get('inventory'))->toBeInstanceOf(InventoryStep::class);
});

it('keeps one snapshot per run when the step is retried', function (): void {
    $run = Run::factory()->create(['type' => RunType::Inventory]);
    $site = $run->site()->firstOrFail();
    $driver = new FakeDriver('6.8.3');

    (new SiteInventory)->take($site, $driver, $run);
    $driver->coreVersion = '6.9.0';
    (new SiteInventory)->take($site, $driver, $run);

    expect(SiteSnapshot::query()->where('run_id', $run->id)->pluck('core_version')->all())->toBe(['6.9.0']);
});

it('stores the environment as a JSON object', function (): void {
    $run = Run::factory()->create(['type' => RunType::Inventory]);
    $snapshot = (new SiteInventory)->take($run->site()->firstOrFail(), new FakeDriver('6.8.3'), $run);

    expect(DB::scalar('select jsonb_typeof(environment) from site_snapshots where id = ?', [$snapshot->id]))->toBe('object');
});

it('refuses, with the real wiring, an inventory whose operator was deactivated', function (): void {
    $user = User::factory()->create(['unix_username' => 'oper9', 'active' => false]);
    $run = Run::factory()->create([
        'type' => RunType::Inventory,
        'user_id' => $user->id,
        'actor' => 'oper9',
        'channel' => AuditChannel::Cli,
    ]);

    $final = app(RunEngine::class)->execute($run);

    expect($final->status)->toBe(RunStatus::Failed)
        ->and(AuditLogEntry::query()->where('action', 'run.denied')->where('object_id', (string) $run->id)->exists())->toBeTrue()
        ->and(AuditLogEntry::query()->where('action', 'secret.reveal')->exists())->toBeFalse()
        ->and(SiteSnapshot::query()->where('run_id', $run->id)->exists())->toBeFalse();
});

it('audits the credential reveal on the channel the run was launched from', function (): void {
    $user = User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    $run = Run::factory()->create(['type' => RunType::Inventory, 'user_id' => $user->id, 'actor' => 'oper1', 'channel' => AuditChannel::Cli]);
    // The step may run in a child process: report the actor through the outcome.
    app(PipelineResolver::class)->register(RunType::Inventory, static fn (): Pipeline => new Pipeline([
        new InventoryStep(new SiteInventory, static fn ($site, AuditActor $actor): FakeDriver => $actor->channel === AuditChannel::Cli && $actor->userId === $user->id
            ? new FakeDriver('6.8.3')
            : throw new DriverException("wrong actor: {$actor->channel->value}"), app(RunAuthorization::class), 60),
    ]));

    $final = app(RunEngine::class)->execute($run);

    expect($final->status)->toBe(RunStatus::Succeeded);
});

it('lets the scheduler run routine operations, but never credential management', function (): void {
    $run = Run::factory()->create(['type' => RunType::Inventory, 'user_id' => null, 'channel' => AuditChannel::System]);

    expect(app(RunAuthorization::class)->actorFor($run, Ability::RunOperations))->not->toBeNull()
        ->and(app(RunAuthorization::class)->actorFor($run, Ability::ManageCredentials))->toBeNull();
});
