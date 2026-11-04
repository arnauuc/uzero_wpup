<?php

declare(strict_types=1);

use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Inventory\SiteState;
use App\Domain\Inventory\SiteStateResult;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\StepContext;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\ComponentType;
use App\Enums\RunType;
use App\Models\Run;
use App\Models\Site;
use Tests\Support\FakeDriver;

const STATE_NOW = 1_800_000_000;

function siteState(): SiteState
{
    return new SiteState(app(SecretRedactor::class), 10, 600);
}

function readState(FakeDriver $driver): SiteStateResult
{
    return siteState()->read($driver, $driver->installedComponents(), STATE_NOW);
}

it('reads the WooCommerce state with the DB version and the overridden templates', function (): void {
    $driver = new FakeDriver;
    $driver->wcDbVersion = '9.8.0';
    $driver->wcDbUpdateNeeded = true;
    $driver->wcOverriddenTemplates = [
        ['file' => 'storefront/woocommerce/cart/cart.php', 'theme_version' => '3.8.0', 'core_version' => '10.1.0'],
        ['file' => 'storefront/woocommerce/checkout/form-billing.php', 'theme_version' => '9.9.0', 'core_version' => '9.9.0'],
        ['file' => 'storefront/woocommerce/notices/error.php', 'theme_version' => '', 'core_version' => '8.6.0'],
        ['file' => 'storefront/woocommerce/custom.php', 'theme_version' => '', 'core_version' => ''],
    ];

    $wc = readState($driver)->woocommerce ?? [];

    expect($wc)->toMatchArray(['version' => '10.1.0', 'db_version' => '9.8.0', 'db_update_needed' => true])
        ->and($wc['overridden_templates'] ?? null)->toBe([
            ['file' => 'storefront/woocommerce/cart/cart.php', 'theme_version' => '3.8.0', 'core_version' => '10.1.0', 'outdated' => true],
            ['file' => 'storefront/woocommerce/checkout/form-billing.php', 'theme_version' => '9.9.0', 'core_version' => '9.9.0', 'outdated' => false],
            ['file' => 'storefront/woocommerce/custom.php', 'theme_version' => null, 'core_version' => null, 'outdated' => false],
            ['file' => 'storefront/woocommerce/notices/error.php', 'theme_version' => null, 'core_version' => '8.6.0', 'outdated' => true],
        ]);
});

it('leaves woocommerce null when WooCommerce is missing or inactive', function (): void {
    $missing = new FakeDriver;
    unset($missing->components['woocommerce:woocommerce']);
    $inactive = new FakeDriver;
    $inactive->addComponent(new ComponentVersion(ComponentType::Woocommerce, 'woocommerce', '10.1.0', false));

    expect(readState($missing)->woocommerce)->toBeNull()
        ->and(readState($inactive)->woocommerce)->toBeNull()
        ->and($missing->wpCalls)->not->toContain(['eval', SiteState::WOOCOMMERCE_SNIPPET]);
});

it('tolerates notices before the JSON and a missing DB version', function (): void {
    $driver = new FakeDriver;
    $driver->wcSnippetOutput = "Notice: something\n".json_encode(['db_version' => null, 'db_update_needed' => false, 'overridden_templates' => []])."\n";

    expect(readState($driver)->woocommerce)->toMatchArray(['db_version' => null, 'db_update_needed' => false, 'overridden_templates' => []]);
});

it('warns and keeps going when the WooCommerce read fails or is not JSON', function (): void {
    $broken = new FakeDriver;
    $broken->wcSnippetOutput = 'Fatal error: boom';
    $failing = new FakeDriver;
    $failing->failingCommands = ['eval'];

    $a = readState($broken);
    $b = readState($failing);

    expect($a->woocommerce)->toMatchArray(['version' => '10.1.0', 'db_version' => null, 'overridden_templates' => []])
        ->and($a->warnings)->toContain("La resposta sobre l'estat de WooCommerce no és JSON vàlid.")
        ->and($a->maintenance)->toBeFalse()
        ->and($b->woocommerce['overridden_templates'] ?? null)->toBe([])
        ->and($b->warnings[0])->toContain("l'estat de WooCommerce");
});

it('counts overdue cron events past the grace period', function (): void {
    $driver = new FakeDriver;
    $driver->cronEvents = [
        ['hook' => 'future', 'next_run_gmt' => gmdate('Y-m-d H:i:s', STATE_NOW + 3600)],
        ['hook' => 'grace', 'next_run_gmt' => gmdate('Y-m-d H:i:s', STATE_NOW - 599)],
        ['hook' => 'late', 'next_run_gmt' => gmdate('Y-m-d H:i:s', STATE_NOW - 900)],
        ['hook' => 'older', 'next_run_gmt' => gmdate('Y-m-d H:i:s', STATE_NOW - 7200)],
    ];

    expect(readState($driver)->cron)->toBe(['disabled' => false, 'overdue_events' => 2, 'oldest_overdue_seconds' => 7200]);
});

it('reports a healthy and a disabled cron', function (): void {
    $driver = new FakeDriver;
    $driver->cronDisabled = true;

    expect(readState($driver)->cron)->toBe(['disabled' => true, 'overdue_events' => 0, 'oldest_overdue_seconds' => null]);
});

it('warns when the cron cannot be read', function (): void {
    $driver = new FakeDriver;
    $driver->failingCommands = ['cron event list'];

    $state = readState($driver);

    expect($state->cron)->toBeNull()
        ->and($state->warnings[0])->toContain('els esdeveniments de cron');
});

it('reads the maintenance mode from the exit code', function (): void {
    $off = new FakeDriver;
    $on = new FakeDriver;
    $on->maintenance = true;
    $fatal = new FakeDriver;
    $fatal->maintenanceExitCode = 255;

    $broken = readState($fatal);

    expect(readState($off)->maintenance)->toBeFalse()
        ->and(readState($on)->maintenance)->toBeTrue()
        ->and(readState($off)->warnings)->toBe([])
        ->and($broken->maintenance)->toBeNull()
        ->and($broken->warnings[0])->toContain('el mode manteniment (codi 255)');
});

it('reads the free disk space and warns when it cannot', function (): void {
    $ok = new FakeDriver(freeDisk: 5_000_000_000);
    $bad = new FakeDriver;
    $bad->diskFails = true;

    expect(readState($ok)->diskFreeBytes)->toBe(5_000_000_000)
        ->and(readState($bad)->diskFreeBytes)->toBeNull()
        ->and(readState($bad)->warnings[0])->toContain("l'espai lliure al disc")
        ->and(readState($bad)->maintenance)->toBeFalse();
});

it('redacts secrets in warnings', function (): void {
    $redactor = new SecretRedactor;
    $redactor->register('hunter2hunter2');
    $driver = new FakeDriver;
    $driver->wpFailure = new RemoteCommandFailed('failed with hunter2hunter2 in the command');

    $warnings = (new SiteState($redactor, 10, 600))->read($driver, $driver->installedComponents(), STATE_NOW)->warnings;

    expect($warnings)->not->toBe([])
        ->and(implode(' ', $warnings))->not->toContain('hunter2hunter2');
});

it('lets a lost connection propagate', function (): void {
    $driver = new FakeDriver;
    $driver->wpFailure = new ConnectionFailed('connexió perduda');

    expect(fn () => readState($driver))->toThrow(ConnectionFailed::class);
});

it('stores the state in environment.state next to php and updates', function (): void {
    $driver = new FakeDriver;
    $driver->maintenance = true;

    $snapshot = (new SiteInventory(null, null, siteState()))->take(Site::factory()->create(), $driver);

    expect($snapshot->environment['state'] ?? null)->toMatchArray(['maintenance' => true, 'disk_free_bytes' => 10_000_000_000, 'warnings' => []])
        ->and($snapshot->environment)->toHaveKeys(['php', 'updates', 'state']);
});

it('leaves environment.state out when no SiteState is injected', function (): void {
    $snapshot = (new SiteInventory)->take(Site::factory()->create(), new FakeDriver);

    expect($snapshot->environment)->not->toHaveKey('state');
});

it('mentions only the relevant state in the inventory step summary', function (): void {
    $driver = new FakeDriver;
    $step = fn (): InventoryStep => new InventoryStep(new SiteInventory(null, null, siteState()), static fn () => $driver, app(RunAuthorization::class), 60);
    $run = Run::factory()->create(['type' => RunType::Inventory]);
    $context = fn (): StepContext => new StepContext($run, false);

    $quiet = $step()->run($context());

    $driver->maintenance = true;
    $driver->wcDbUpdateNeeded = true;
    $driver->wcOverriddenTemplates = [['file' => 'storefront/woocommerce/cart/cart.php', 'theme_version' => '3.8.0', 'core_version' => '10.1.0']];
    $driver->cronEvents = [['hook' => 'late', 'next_run_gmt' => gmdate('Y-m-d H:i:s', time() - 7200)]];
    $loud = $step()->run($context());

    expect($quiet->summary)->not->toContain('manteniment')
        ->and($loud->summary)->toContain('manteniment actiu')
        ->and($loud->summary)->toContain("BD de WooCommerce pendent d'actualitzar")
        ->and($loud->summary)->toContain('1 plantilles sobreescrites (1 desfasades)')
        ->and($loud->summary)->toContain('1 esdeveniments de cron endarrerits');
});
