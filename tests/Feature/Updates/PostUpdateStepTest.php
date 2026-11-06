<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Data\CommandResult;
use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\StepContext;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Updates\PageCacheCommand;
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
        app(RunAuthorization::class), app(SecretRedactor::class),
        new PostUpdateSettings(6600, 1800, 120, 900, ['wp-rocket' => new PageCacheCommand('rocket clean', ['--confirm']), 'w3-total-cache' => new PageCacheCommand('w3-total-cache flush', ['all'])]),
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
    $driver->cliCommands = ['rocket clean'];
    $driver->commandResults['rocket clean --confirm'] = new CommandResult(0, "Success: Cache cleared.\n", '', 1);

    $outcome = postStep($driver)->run(postContext());

    expect(postCommands($driver))->toContain('cli has-command rocket clean')->toContain('rocket clean --confirm')
        ->and(postCommands($driver))->not->toContain('w3-total-cache flush all')
        ->and($outcome->data['page_cache_purged'])->toBeTrue()
        ->and($outcome->data['page_cache_plugins'])->toBe(['wp-rocket']);
});

it('only warns when a flush fails', function (): void {
    $driver = new FakeDriver;
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'wp-rocket', '3.17.0'));
    $driver->cliCommands = ['rocket clean'];
    $driver->failingCommands = ['cache flush', 'rocket clean'];

    $outcome = postStep($driver)->run(postContext());

    expect($outcome->status)->toBe(RunStepStatus::Succeeded)
        ->and($outcome->data['flush_warnings'])->toBe(2)
        ->and($outcome->data['page_cache_purged'])->toBeFalse()
        ->and($outcome->data['page_cache_failed'])->toBe(['wp-rocket'])
        ->and($outcome->summary)->toContain("la memòria cau d'objectes")->toContain('wp-rocket')
        // The other flushes still ran.
        ->and(postCommands($driver))->toContain('transient delete --expired --skip-plugins --skip-themes');
});

it('warns and flags the page cache when an active plugin has no WP-CLI command', function (): void {
    $driver = new FakeDriver;
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'wp-rocket', '3.17.0'));

    $outcome = postStep($driver)->run(postContext());

    expect($outcome->status)->toBe(RunStepStatus::Succeeded)
        ->and($outcome->summary)->toContain('wp-rocket està actiu però no té comanda WP-CLI per buidar-ne la cau')
        ->and($outcome->data['page_cache_purged'])->toBeFalse()
        ->and(postCommands($driver))->not->toContain('rocket clean --confirm');
});

it('reports the page cache as purged when no caching plugin is active', function (): void {
    $outcome = postStep(new FakeDriver)->run(postContext());

    expect($outcome->data['page_cache_purged'])->toBeTrue()->and($outcome->data['page_cache_plugins'])->toBe([]);
});

it('fails the step when the connection is lost while flushing', function (): void {
    $driver = new class extends FakeDriver
    {
        public function wp(array $args, ?int $timeoutSeconds = null): CommandResult
        {
            if ($args[0] === 'cache') {
                throw new ConnectionFailed('connexió perduda');
            }

            return parent::wp($args, $timeoutSeconds);
        }
    };

    $outcome = postStep($driver)->run(postContext());

    expect($outcome->status)->toBe(RunStepStatus::Failed)->and($outcome->summary)->toContain('connexió perduda');
});

it('only checks WooCommerce once, after wc update, when it was updated', function (): void {
    $driver = new FakeDriver;
    $driver->wcDbUpdateNeeded = true;

    postStep($driver)->run(postContext());

    expect(count(array_filter($driver->wpCalls, static fn (array $a): bool => $a[0] === 'eval')))->toBe(1);
});

it('describes wc update when it may run', function (): void {
    $context = postContext();

    expect(postStep(new FakeDriver)->describe($context))->toContain('wp wc update')
        ->and(postStep(new FakeDriver, [])->describe($context))->toContain('wp wc update');
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
    $driver->cliCommands = ['rocket clean'];
    $driver->commandResults['rocket clean --confirm'] = new CommandResult(0, 'token s3cr3t-value', '', 1);

    expect(postStep($driver)->run(postContext())->output)->not->toContain('s3cr3t-value');
});

it('refuses a step timeout that cannot cover its commands', function (): void {
    // 2 × 1800 + (4 + 2 × 1) × 120 + 900 = 5220
    expect(fn () => new PostUpdateSettings(5000, 1800, 120, 900, ['a' => new PageCacheCommand('a b')]))->toThrow(InvalidArgumentException::class, '5220')
        ->and(fn () => new PostUpdateSettings(6600, 1800, 0, 900, []))->toThrow(InvalidArgumentException::class, 'command_timeout_seconds')
        ->and(fn () => new PostUpdateSettings(6600, 1800, 120, -1, []))->toThrow(InvalidArgumentException::class, 'overhead_seconds');
});
