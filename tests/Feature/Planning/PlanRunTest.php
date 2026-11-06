<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\Data\PhpRuntime;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Inventory\PendingUpdates;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Inventory\SiteState;
use App\Domain\Planning\PlanStep;
use App\Domain\Planning\UpdatePlanner;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\RunEngine;
use App\Domain\Runs\StepContext;
use App\Domain\Sites\SiteBlocking;
use App\Enums\ChangeResult;
use App\Enums\ComponentType;
use App\Enums\PhpVersionSource;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\SkipReason;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\PackageVersion;
use App\Models\Run;
use App\Models\RunChange;
use App\Models\Site;
use App\Models\SiteProfile;
use App\Models\SiteSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeDriver;

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    config(['wpup.runs.heartbeat_seconds' => 1]);
    // The quarantine has its own tests (VersionPolicyTest and below).
    config(['wpup.planning.quarantine_days' => 0]);
    Http::fake(['*' => Http::response('', 500)]);
});

/**
 * @param  list<string>|null  $classicRequiresPlugins
 */
function plannedDriver(?string $helloRequiresPhp = null, string $helloTarget = '1.7.2', ?array $classicRequiresPlugins = null): FakeDriver
{
    $driver = new FakeDriver('6.8.1');
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'hello', '1.6'));
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'premium', '2.0.0'));
    $driver->transients['update_plugins'] = [
        'last_checked' => time(),
        'response' => [
            'hello.php' => ['id' => 'w.org/plugins/hello-dolly', 'slug' => 'hello-dolly', 'new_version' => $helloTarget, 'package' => 'https://downloads.wordpress.org/plugin/hello-dolly.1.7.2.zip', 'requires_php' => $helloRequiresPhp],
            'premium/premium.php' => ['slug' => 'premium', 'new_version' => '2.1.0', 'package' => ''],
            'classic-editor/classic-editor.php' => ['id' => 'w.org/plugins/classic-editor', 'slug' => 'classic-editor', 'new_version' => '1.6.4', 'package' => 'https://downloads.wordpress.org/plugin/classic-editor.1.6.4.zip', 'requires_plugins' => $classicRequiresPlugins],
        ],
    ];
    $driver->coreUpdates = [['version' => '6.8.2', 'update_type' => 'minor', 'package_url' => 'https://downloads.wordpress.org/release/wordpress-6.8.2.zip']];

    return $driver;
}

/**
 * @return array{php: array{version: string, source: string, verified: bool}, blocked_reason: ?string, changes: list<array{slug: string, action: string, reason: ?string}>}
 */
function planResult(Run $run): array
{
    /** @var array{php: array{version: string, source: string, verified: bool}, blocked_reason: ?string, changes: list<array{slug: string, action: string, reason: ?string}>} $result */
    $result = $run->steps()->where('name', 'plan')->firstOrFail()->result;

    return $result;
}

function planRun(FakeDriver $driver, Site $site): Run
{
    app(PipelineResolver::class)->register(RunType::Plan, static fn (): Pipeline => new Pipeline([
        new InventoryStep(app(SiteInventory::class), static fn (Site $s, AuditActor $a): SiteDriver => $driver, app(RunAuthorization::class), 60, app(SiteBlocking::class), readOnlySite: true),
        new PlanStep(app(UpdatePlanner::class), app(RunAuthorization::class), 60, 604800),
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

it('records each skipped component with its precise reason in run_changes, once per run', function (): void {
    $site = Site::factory()->create();
    $driver = plannedDriver(helloTarget: '2.0.0', classicRequiresPlugins: ['woocommerce-subscriptions']);

    $run = planRun($driver, $site);
    // A row written by a later step survives a retried plan step, which replaces only its own rows.
    RunChange::factory()->create(['run_id' => $run->id, 'step' => 'update', 'slug' => 'zz-later', 'result' => ChangeResult::Skipped, 'reason' => SkipReason::Other]);
    (new PlanStep(app(UpdatePlanner::class), app(RunAuthorization::class), 60, 604800))->run(new StepContext($run->refresh(), false, ['inventory' => ['snapshot_id' => SiteSnapshot::query()->where('run_id', $run->id)->value('id')]]));

    $rows = $run->changes()->orderBy('slug')->get()->map(static fn (RunChange $c): array => [
        $c->slug, $c->result->value, $c->reason?->value, $c->version_before, $c->version_after, $c->is_major, $c->reason_detail,
    ])->all();

    expect($rows)->toBe([
        ['classic-editor', 'skipped', 'dependency', '1.6.3', '1.6.4', false, 'Requereix el plugin «woocommerce-subscriptions», que no està instal·lat.'],
        ['hello', 'skipped', 'major_requires_staging', '1.6', '2.0.0', true, 'Canvi major (1.6 → 2.0.0): cal staging primer.'],
        ['premium', 'skipped', 'no_license', '2.0.0', '2.1.0', false, 'No hi ha cap paquet descarregable: falta la llicència.'],
        ['zz-later', 'skipped', 'other', '1.0.0', '1.0.1', false, null],
    ]);
    expect($run->changes()->where('step', 'plan')->count())->toBe(3);
});

it('cuts a site-provided version that does not fit run_changes instead of failing', function (): void {
    $target = '2.'.str_repeat('0', 300);

    $run = planRun(plannedDriver(helloTarget: $target), Site::factory()->create());
    $hello = $run->changes()->where('slug', 'hello')->firstOrFail();

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($hello->version_after)->toBe(substr($target, 0, 255))
        ->and($hello->reason)->toBe(SkipReason::MajorRequiresStaging);
});

it('uses a recent web-verified PHP of the same minor in place of the CLI guess', function (): void {
    $site = Site::factory()->create();
    SiteSnapshot::factory()->create(['site_id' => $site->id, 'php_version' => '8.3.9', 'php_source' => PhpVersionSource::HttpProbe, 'taken_at' => now()->subDay()]);
    $driver = plannedDriver('8.3.12');
    $driver->phpRuntime = new PhpRuntime('8.3.14', 'php', PhpVersionSource::Cli, []);

    $result = planResult(planRun($driver, $site));

    expect($result['php'])->toMatchArray(['version' => '8.3.9', 'source' => 'http_probe', 'verified' => true])
        ->and(collect($result['changes'])->firstWhere('slug', 'hello')['reason'] ?? null)->toBe('php_incompatible');
});

it('does not trust a stale or different-minor web-verified PHP', function (string $version, int $ageDays): void {
    $site = Site::factory()->create();
    SiteSnapshot::factory()->create(['site_id' => $site->id, 'php_version' => $version, 'php_source' => PhpVersionSource::HttpProbe, 'taken_at' => now()->subDays($ageDays)]);
    $driver = plannedDriver();
    $driver->phpRuntime = new PhpRuntime('8.3.14', 'php', PhpVersionSource::Cli, []);

    $result = planResult(planRun($driver, $site));

    expect($result['php'])->toMatchArray(['version' => '8.3.14', 'source' => 'cli', 'verified' => false]);
})->with([
    'stale' => ['8.3.9', 30],
    'other minor' => ['7.4.33', 1],
]);

it('picks the latest web-verified snapshot by taken_at, within the max age', function (): void {
    $site = Site::factory()->create();
    $newer = SiteSnapshot::factory()->create(['site_id' => $site->id, 'php_version' => '8.2.1', 'php_source' => PhpVersionSource::HttpProbe, 'taken_at' => now()->subDay()]);
    SiteSnapshot::factory()->create(['site_id' => $site->id, 'php_version' => '8.1.1', 'php_source' => PhpVersionSource::HttpProbe, 'taken_at' => now()->subDays(5)]);
    SiteSnapshot::factory()->create(['site_id' => $site->id, 'php_version' => '8.4.1', 'php_source' => PhpVersionSource::Cli, 'taken_at' => now()]);

    expect(SiteSnapshot::latestWebVerifiedPhp($site)?->id)->toBe($newer->id)
        ->and(SiteSnapshot::latestWebVerifiedPhp($site, 3600))->toBeNull()
        ->and(SiteSnapshot::latestWebVerifiedPhp(Site::factory()->create()))->toBeNull();
});

it('skips everything with the reason a real run would block the site for', function (): void {
    $site = Site::factory()->create();
    // Little free disk: the backup_space prerequisite fails.
    $driver = plannedDriver();
    $driver->freeDisk = 1000;

    $run = planRun($driver, $site);
    $result = planResult($run);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($result['blocked_reason'])->toStartWith('es bloquejaria: espai insuficient')
        ->and(array_unique(array_column($result['changes'], 'reason')))->toBe(['site_blocked'])
        ->and($site->refresh()->blocked_reason)->toBeNull();
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
            $known = match ($call[0]) {
                // Only the inventory's constant snippets, never an arbitrary eval.
                'eval' => count($call) === 2 && in_array($call[1], [SiteState::SNIPPET, PendingUpdates::REQUIRES_PLUGINS_SNIPPET, 'echo $GLOBALS["wpdb"]->db_server_info();'], true),
                'core' => in_array($call[1] ?? '', ['version', 'check-update'], true),
                'transient' => ($call[1] ?? '') === 'get',
                'option' => ($call[1] ?? '') === 'get',
                'maintenance-mode' => ($call[1] ?? '') === 'is-active',
                'db' => ($call[1] ?? '') === 'size',
                default => false,
            };
            expect($known)->toBeTrue('unexpected WP-CLI call: '.implode(' ', $call));
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
    $outcome = new PlanStep(app(UpdatePlanner::class), app(RunAuthorization::class), 60, 604800)->run(new StepContext($run, false, []));

    expect($outcome->status->value)->toBe('failed');
});

function realPlanPipeline(FakeDriver|Throwable $driver): void
{
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('oper1');
    app(PipelineResolver::class)->register(RunType::Plan, static fn (): Pipeline => new Pipeline([
        new InventoryStep(app(SiteInventory::class), static function (Site $s, AuditActor $a) use ($driver): SiteDriver {
            if ($driver instanceof Throwable) {
                throw $driver;
            }

            return $driver;
        }, app(RunAuthorization::class), 60, app(SiteBlocking::class), readOnlySite: true),
        new PlanStep(app(UpdatePlanner::class), app(RunAuthorization::class), 60, 604800),
    ]));
}

it('runs wpup plan --wait --json end to end with the real pipeline', function (): void {
    $site = Site::factory()->create(['name' => 'botiga-e2e']);
    SiteProfile::factory()->create(['site_id' => $site->id, 'frozen_components' => ['hello']]);
    $driver = plannedDriver();
    realPlanPipeline($driver);

    $exit = Artisan::call('plan', ['sites' => ['botiga-e2e'], '--wait' => true, '--json' => true]);
    /** @var array{version: int, sites: list<array{site: string, status: string, blocked: bool, plan: array{updates: int, skips: int, changes: list<array{slug: string, action: string}>}}>} $json */
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($json['version'])->toBe(1)
        ->and($json['sites'][0])->toMatchArray(['site' => 'botiga-e2e', 'status' => 'succeeded', 'blocked' => false])
        ->and($json['sites'][0]['plan'])->toMatchArray(['updates' => 2, 'skips' => 2])
        ->and(array_column($json['sites'][0]['plan']['changes'], 'action', 'slug'))->toBe(['wordpress' => 'update', 'classic-editor' => 'update', 'hello' => 'skip', 'premium' => 'skip'])
        ->and($driver->writeCalls)->toBe([]);
});

it('reports a failed plan run with the site block taken from the site', function (): void {
    Site::factory()->create(['name' => 'caigut', 'blocked_reason' => 'Bloqueig anterior', 'blocked_at' => now()]);
    realPlanPipeline(new ConnectionFailed('WP-CLI no respon'));

    $exit = Artisan::call('plan', ['sites' => ['caigut'], '--wait' => true, '--json' => true]);
    /** @var array{sites: list<array{status: string, blocked: bool|null, failure: ?string, plan: mixed}>} $json */
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($json['sites'][0])->toMatchArray(['status' => 'failed', 'blocked' => true, 'plan' => null])
        ->and($json['sites'][0]['failure'])->toContain('WP-CLI no respon');
});

it('holds new versions seen by the inventory until the quarantine is over (ADR-0005)', function (): void {
    config(['wpup.planning.quarantine_days' => 7]);
    Carbon::setTestNow(CarbonImmutable::parse('2026-11-06 12:00:00'));
    $site = Site::factory()->create();

    $first = planRun(plannedDriver(), $site);
    $quarantined = RunChange::query()->where('run_id', $first->id)->where('slug', 'classic-editor')->sole();
    expect($quarantined->reason)->toBe(SkipReason::Quarantine)
        ->and($quarantined->reason_detail)->toContain('falten 7 dies')
        // The core x.y.z does not wait.
        ->and(array_column(planResult($first)['changes'], 'action', 'slug')['wordpress'])->toBe('update');

    Carbon::setTestNow(now()->addDays(8));
    $second = planRun(plannedDriver(), $site);

    expect(array_column(planResult($second)['changes'], 'action', 'slug')['classic-editor'])->toBe('update')
        ->and(PackageVersion::query()->where('slug', 'classic-editor')->sole()->first_seen_at->equalTo(CarbonImmutable::parse('2026-11-06 12:00:00')))->toBeTrue();
});

it('fails the plan step when the operator who asked for an override is no longer an admin', function (): void {
    $user = User::factory()->create(['unix_username' => 'exadmin', 'role' => UserRole::Operator]);
    $site = Site::factory()->create();
    app(PipelineResolver::class)->register(RunType::Plan, static fn (): Pipeline => new Pipeline([
        new InventoryStep(app(SiteInventory::class), static fn (Site $s, AuditActor $a): SiteDriver => plannedDriver(), app(RunAuthorization::class), 60, app(SiteBlocking::class), readOnlySite: true),
        new PlanStep(app(UpdatePlanner::class), app(RunAuthorization::class), 60, 604800),
    ]));
    $run = Run::factory()->create(['type' => RunType::Plan, 'site_id' => $site->id, 'user_id' => $user->id, 'actor' => 'exadmin', 'flags' => ['allow_major' => true]]);

    $run = app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->steps()->where('name', 'plan')->sole()->summary)->toContain('ja no té permís')
        ->and(AuditLogEntry::query()->where('action', 'run.denied')->sole()->context)->toMatchArray(['ability' => 'override-version-policy']);
});
