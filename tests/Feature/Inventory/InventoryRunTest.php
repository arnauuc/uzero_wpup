<?php

declare(strict_types=1);

use App\Domain\Inventory\InventoryStep;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunEngine;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Run;
use App\Models\SiteSnapshot;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeDriver;

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    config(['wpup.runs.heartbeat_seconds' => 1]);
});

it('creates one fresh snapshot per inventory run, linked to the run', function (): void {
    $driver = new FakeDriver('6.8.3');
    app(PipelineResolver::class)->register(RunType::Inventory, static fn (): Pipeline => new Pipeline([
        new InventoryStep(new SiteInventory, static fn () => $driver, 60),
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
