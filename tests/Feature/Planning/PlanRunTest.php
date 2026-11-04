<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\Data\PhpRuntime;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Planning\PlanStep;
use App\Domain\Planning\UpdatePlanner;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\RunEngine;
use App\Domain\Runs\StepContext;
use App\Domain\Sites\SiteBlocking;
use App\Enums\ComponentType;
use App\Enums\PhpVersionSource;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\AuditLogEntry;
use App\Models\Run;
use App\Models\Site;
use App\Models\SiteProfile;
use App\Models\SiteSnapshot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeDriver;

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    config(['wpup.runs.heartbeat_seconds' => 1]);
    Http::fake(['*' => Http::response('', 500)]);
});

function plannedDriver(?string $helloRequiresPhp = null): FakeDriver
{
    $driver = new FakeDriver('6.8.1');
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'hello', '1.6'));
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'premium', '2.0.0'));
    $driver->transients['update_plugins'] = [
        'last_checked' => time(),
        'response' => [
            'hello.php' => ['id' => 'w.org/plugins/hello-dolly', 'slug' => 'hello-dolly', 'new_version' => '1.7.2', 'package' => 'https://downloads.wordpress.org/plugin/hello-dolly.1.7.2.zip', 'requires_php' => $helloRequiresPhp],
            'premium/premium.php' => ['slug' => 'premium', 'new_version' => '2.1.0', 'package' => ''],
            'classic-editor/classic-editor.php' => ['id' => 'w.org/plugins/classic-editor', 'slug' => 'classic-editor', 'new_version' => '1.6.4', 'package' => 'https://downloads.wordpress.org/plugin/classic-editor.1.6.4.zip'],
        ],
    ];
    $driver->coreUpdates = [['version' => '6.8.2', 'update_type' => 'minor', 'package_url' => 'https://downloads.wordpress.org/release/wordpress-6.8.2.zip']];

    return $driver;
}

function planRun(FakeDriver $driver, Site $site): Run
{
    app(PipelineResolver::class)->register(RunType::Plan, static fn (): Pipeline => new Pipeline([
        new InventoryStep(app(SiteInventory::class), static fn (Site $s, AuditActor $a): SiteDriver => $driver, app(RunAuthorization::class), 60, app(SiteBlocking::class), readOnlySite: true),
        new PlanStep(app(UpdatePlanner::class), 60),
    ]));
    $run = Run::factory()->create(['type' => RunType::Plan, 'site_id' => $site->id]);

    return app(RunEngine::class)->execute($run);
}

it('registers the plan pipeline: a read-only inventory, then the plan', function (): void {
    $pipeline = app(PipelineResolver::class)->resolve(Run::factory()->create(['type' => RunType::Plan]));

    expect($pipeline->get('inventory'))->toBeInstanceOf(InventoryStep::class)
        ->and($pipeline->get('plan'))->toBeInstanceOf(PlanStep::class);
});

it('plans from a fresh inventory and stores the plan in the step', function (): void {
    $site = Site::factory()->create();
    SiteProfile::factory()->create(['site_id' => $site->id, 'frozen_components' => ['hello']]);

    $run = planRun(plannedDriver(), $site);
    $step = $run->steps()->where('name', 'plan')->firstOrFail();

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($step->summary)->toBe("2 s'actualitzarien, 2 s'ometrien");
    /** @var array{changes: list<array{slug: string, action: string, reason: ?string}>} $result */
    $result = $step->result;
    expect(array_map(static fn (array $c): string => "{$c['slug']}:{$c['action']}:{$c['reason']}", $result['changes']))->toBe([
        'wordpress:update:', 'classic-editor:update:', 'hello:skip:frozen', 'premium:skip:no_license',
    ]);
});

it('prefers an earlier web-verified PHP over the CLI guess', function (): void {
    $site = Site::factory()->create();
    SiteSnapshot::factory()->create(['site_id' => $site->id, 'php_version' => '7.4.33', 'php_source' => PhpVersionSource::HttpProbe]);
    $driver = plannedDriver('8.0');
    $driver->phpRuntime = new PhpRuntime('8.3.14', 'php', PhpVersionSource::Cli, []);

    $run = planRun($driver, $site);
    /** @var array{php: array{version: string, source: string}, changes: list<array{slug: string, reason: ?string}>} $result */
    $result = $run->steps()->where('name', 'plan')->firstOrFail()->result;

    expect($result['php'])->toMatchArray(['version' => '7.4.33', 'source' => 'http_probe'])
        ->and(collect($result['changes'])->firstWhere('slug', 'hello')['reason'] ?? null)->toBe('php_incompatible');
});

it('writes nothing to the site and leaves its block alone', function (): void {
    // A site that the prerequisites would block (the CLI PHP is not web-verified)
    // and a blocked one that they would release: neither changes in a plan run.
    $free = Site::factory()->create();
    $blocked = Site::factory()->create(['blocked_reason' => 'Bloqueig anterior', 'blocked_at' => now()->subDay()]);

    foreach ([$free, $blocked] as $site) {
        $driver = plannedDriver();
        $run = planRun($driver, $site);

        expect($run->status)->toBe(RunStatus::Succeeded)
            ->and($driver->writeCalls)->toBe([])
            ->and($driver->webFilesWritten)->toBe([])
            ->and($driver->maintenance)->toBeFalse();
        foreach ($driver->wpCalls as $call) {
            expect($call[0])->toBeIn(['eval', 'core', 'transient', 'option', 'maintenance-mode', 'db'], 'unexpected WP-CLI call: '.implode(' ', $call));
            expect(array_intersect($call, ['update', 'install', 'activate', 'delete', 'import']))->toBe([]);
        }
    }

    expect($free->refresh()->blocked_reason)->toBeNull()
        ->and($blocked->refresh()->blocked_reason)->toBe('Bloqueig anterior')
        ->and(AuditLogEntry::query()->where('action', 'like', 'site.%block%')->count())->toBe(0);
});

it('skips every update of an already blocked site', function (): void {
    $site = Site::factory()->create(['blocked_reason' => 'Bloqueig anterior', 'blocked_at' => now()]);

    $run = planRun(plannedDriver(), $site);
    /** @var array{blocked_reason: ?string, changes: list<array{reason: ?string}>} $result */
    $result = $run->steps()->where('name', 'plan')->firstOrFail()->result;

    expect($result['blocked_reason'])->toBe('Bloqueig anterior')
        ->and(array_unique(array_column($result['changes'], 'reason')))->toBe(['site_blocked']);
});

it('fails the plan step when the inventory left no snapshot', function (): void {
    $run = Run::factory()->create(['type' => RunType::Plan]);
    $outcome = new PlanStep(app(UpdatePlanner::class), 60)->run(new StepContext($run, false, []));

    expect($outcome->status->value)->toBe('failed');
});
