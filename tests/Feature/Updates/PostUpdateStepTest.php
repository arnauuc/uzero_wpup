<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Data\CommandResult;
use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\SiteState;
use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\StepContext;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Updates\PostUpdateSettings;
use App\Domain\Updates\PostUpdateStep;
use App\Enums\ComponentType;
use App\Enums\RunStepStatus;
use App\Models\Run;
use App\Models\Site;
use Tests\Support\FakeDriver;

beforeEach(function (): void {
    app(SecretRedactor::class)->reset();
});

/**
 * @param  list<PlannedChange>|null  $changes  defaults to a WooCommerce update
 */
function postStep(FakeDriver $driver, ?array $changes = null): PostUpdateStep
{
    return new PostUpdateStep(
        $changes ?? [new PlannedChange(ComponentType::Woocommerce, 'woocommerce', '10.0.0', '10.1.0', PlanAction::Update)],
        static fn (Site $s, AuditActor $a): SiteDriver => $driver,
        app(RunAuthorization::class), app(SiteState::class), app(SecretRedactor::class),
        new PostUpdateSettings(4500, 1800, 120, ['wp-rocket' => ['rocket', 'clean', '--confirm'], 'w3-total-cache' => ['w3tc', 'flush', 'all']]),
    );
}

function postContext(): StepContext
{
    return new StepContext(Run::factory()->create(['site_id' => Site::factory()]), false);
}

/**
 * The WP-CLI commands run, without the state reads.
 *
 * @return list<string>
 */
function postCommands(FakeDriver $driver): array
{
    return array_values(array_map(
        static fn (array $args): string => implode(' ', $args),
        array_filter($driver->wpCalls, static fn (array $args): bool => $args[0] !== 'eval'),
    ));
}

it('migrates the databases, checks WooCommerce and flushes the caches', function (): void {
    $driver = new FakeDriver;
    $driver->wcDbUpdateNeeded = true;

    $outcome = postStep($driver)->run(postContext());

    expect($outcome->status)->toBe(RunStepStatus::Succeeded)
        ->and($driver->wcDbUpdateNeeded)->toBeFalse()
        ->and(postCommands($driver))->toBe([
            'core update-db --skip-plugins --skip-themes',
            'wc update --skip-themes',
            'cache flush --skip-plugins --skip-themes',
            'transient delete --expired --skip-plugins --skip-themes',
        ]);
});

it('fails when the WooCommerce migration stays pending after wc update', function (): void {
    $driver = new FakeDriver;
    $driver->wcDbUpdateNeeded = true;
    $driver->wcUpdateLeavesPending = true;

    $outcome = postStep($driver)->run(postContext());

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain('pendent')
        // No flush after an error: the update run decides about the rollback.
        ->and(postCommands($driver))->not->toContain('cache flush --skip-plugins --skip-themes');
});

it('fails when wc update fails', function (): void {
    $driver = new FakeDriver;
    $driver->failingCommands = ['wc update'];

    $outcome = postStep($driver)->run(postContext());

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain('wp wc update')
        ->and($outcome->output)->toContain('Error: wc update failed')
        ->and(postCommands($driver))->not->toContain('transient delete --expired --skip-plugins --skip-themes');
});

it('fails when core update-db fails, without touching WooCommerce', function (): void {
    $driver = new FakeDriver;
    $driver->failingCommands = ['core update-db'];

    $outcome = postStep($driver)->run(postContext());

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and(postCommands($driver))->toBe(['core update-db --skip-plugins --skip-themes']);
});

it('fails when the WooCommerce state cannot be read afterwards', function (): void {
    $driver = new FakeDriver;
    $driver->wcFails = true;

    $outcome = postStep($driver)->run(postContext());

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain("no s'ha pogut comprovar");
});

it('fails when the connection is lost', function (): void {
    $driver = new FakeDriver;
    $driver->wpFailure = new ConnectionFailed('connexió perduda');

    $outcome = postStep($driver)->run(postContext());

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain('connexió perduda');
});

it('runs wc update for a pending migration even when WooCommerce was not updated', function (): void {
    $driver = new FakeDriver;
    $driver->wcDbUpdateNeeded = true;

    $outcome = postStep($driver, [])->run(postContext());

    expect($outcome->status)->toBe(RunStepStatus::Succeeded)
        ->and(postCommands($driver))->toContain('wc update --skip-themes');
});

it('does not run wc update when WooCommerce was not updated and nothing is pending', function (): void {
    $driver = new FakeDriver;

    expect(postStep($driver, [])->run(postContext())->status)->toBe(RunStepStatus::Succeeded)
        ->and(postCommands($driver))->not->toContain('wc update --skip-themes');
});

it('skips the WooCommerce part when the plugin is inactive', function (): void {
    $driver = new FakeDriver;
    $driver->addComponent(new ComponentVersion(ComponentType::Woocommerce, 'woocommerce', '10.1.0', false));

    expect(postStep($driver)->run(postContext())->status)->toBe(RunStepStatus::Succeeded)
        ->and(postCommands($driver))->not->toContain('wc update --skip-themes');
});

it('purges the page cache of the active caching plugins only', function (): void {
    $driver = new FakeDriver;
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'wp-rocket', '3.17.0'));
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'w3-total-cache', '2.8.0', false));
    $driver->commandResults['rocket clean --confirm'] = new CommandResult(0, "Success: Cache cleared.\n", '', 1);

    postStep($driver)->run(postContext());

    expect(postCommands($driver))->toContain('rocket clean --confirm')
        ->and(postCommands($driver))->not->toContain('w3tc flush all');
});

it('only warns when a flush fails', function (): void {
    $driver = new FakeDriver;
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'wp-rocket', '3.17.0'));
    $driver->failingCommands = ['cache flush', 'rocket clean'];

    $outcome = postStep($driver)->run(postContext());

    expect($outcome->status)->toBe(RunStepStatus::Succeeded)
        ->and($outcome->data['flush_warnings'])->toBe(2)
        ->and($outcome->summary)->toContain("la memòria cau d'objectes")->toContain('wp-rocket')
        // The other flushes still ran.
        ->and(postCommands($driver))->toContain('transient delete --expired --skip-plugins --skip-themes');
});

it('is idempotent: the second run succeeds and does not migrate again', function (): void {
    $driver = new FakeDriver;
    $driver->wcDbUpdateNeeded = true;
    $step = postStep($driver, []);

    expect($step->run(postContext())->status)->toBe(RunStepStatus::Succeeded);
    $driver->wpCalls = [];
    expect($step->run(postContext())->status)->toBe(RunStepStatus::Succeeded)
        ->and(postCommands($driver))->not->toContain('wc update --skip-themes');
});

it('masks secrets in the output', function (): void {
    $driver = new FakeDriver;
    app(SecretRedactor::class)->register('s3cr3t-value');
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'wp-rocket', '3.17.0'));
    $driver->commandResults['rocket clean --confirm'] = new CommandResult(0, 'token s3cr3t-value', '', 1);

    expect(postStep($driver)->run(postContext())->output)->not->toContain('s3cr3t-value');
});

it('refuses a step timeout that cannot cover its commands', function (): void {
    expect(fn () => new PostUpdateSettings(100, 1800, 120, []))->toThrow(InvalidArgumentException::class, '3840');
});
