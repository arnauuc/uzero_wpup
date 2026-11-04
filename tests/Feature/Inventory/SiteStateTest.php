<?php

declare(strict_types=1);

use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Inventory\SiteState;
use App\Domain\Inventory\SiteStateResult;
use App\Domain\Inventory\WpCliReader;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\ComponentType;
use App\Enums\RunType;
use App\Models\Run;
use App\Models\Site;
use Tests\Support\FakeDriver;

const STATE_NOW = 1_800_000_000;

function siteState(?SecretRedactor $redactor = null): SiteState
{
    return new SiteState(new WpCliReader($redactor ?? app(SecretRedactor::class)), 10, 600, 600);
}

function stateDriver(): FakeDriver
{
    $driver = new FakeDriver;
    $driver->siteTime = STATE_NOW;

    return $driver;
}

function readState(FakeDriver $driver, ?SiteState $state = null): SiteStateResult
{
    return ($state ?? siteState())->read($driver, $driver->installedComponents(), STATE_NOW);
}

it('reads the WooCommerce state with the DB version and the overridden templates', function (): void {
    $driver = stateDriver();
    $driver->wcDbVersion = '9.8.0';
    $driver->wcDbUpdateNeeded = true;
    $driver->wcOverriddenTemplates = [
        ['file' => 'storefront/woocommerce/cart/cart.php', 'theme_version' => '3.8.0', 'core_version' => '10.1.0'],
        ['file' => 'storefront/woocommerce/checkout/form-billing.php', 'theme_version' => '9.9.0', 'core_version' => '9.9.0'],
        ['file' => 'storefront/woocommerce/notices/error.php', 'theme_version' => '', 'core_version' => '8.6.0'],
        ['file' => 'storefront/woocommerce/custom.php', 'theme_version' => '', 'core_version' => ''],
    ];

    $wc = readState($driver)->woocommerce?->toArray();

    expect($wc)->toMatchArray(['version' => '10.1.0', 'db_version' => '9.8.0', 'db_update_needed' => true])
        ->and($wc['overridden_templates'] ?? null)->toBe([
            ['file' => 'storefront/woocommerce/cart/cart.php', 'theme_version' => '3.8.0', 'core_version' => '10.1.0', 'outdated' => true],
            ['file' => 'storefront/woocommerce/checkout/form-billing.php', 'theme_version' => '9.9.0', 'core_version' => '9.9.0', 'outdated' => false],
            ['file' => 'storefront/woocommerce/custom.php', 'theme_version' => null, 'core_version' => null, 'outdated' => false],
            ['file' => 'storefront/woocommerce/notices/error.php', 'theme_version' => null, 'core_version' => '8.6.0', 'outdated' => true],
        ]);
});

it('leaves woocommerce null when WooCommerce is missing or inactive', function (): void {
    $missing = stateDriver();
    unset($missing->components['woocommerce:woocommerce']);
    $inactive = stateDriver();
    $inactive->addComponent(new ComponentVersion(ComponentType::Woocommerce, 'woocommerce', '10.1.0', false));

    expect(readState($missing)->woocommerce)->toBeNull()
        ->and(readState($inactive)->woocommerce)->toBeNull()
        ->and(readState($missing)->warnings)->toBe([]);
});

it('tolerates notices before the JSON, a missing DB version and an unknown DB update', function (): void {
    $driver = stateDriver();
    $driver->wcDbVersion = null;
    $driver->wcDbUpdateNeeded = null;
    $driver->snippetOutput = "Notice: something\n".$driver->stateSnippet()."\n";

    $wc = readState($driver)->woocommerce;

    expect($wc?->dbVersion)->toBeNull()
        ->and($wc?->dbUpdateNeeded)->toBeNull()
        ->and($wc?->overriddenTemplates)->toBe([])
        ->and(readState($driver)->warnings)->toBe([]);
});

it('reports unknown WooCommerce fields as null, not as all fine, when its part fails', function (): void {
    $driver = stateDriver();
    $driver->wcFails = true;
    $driver->maintenance = true;

    $state = readState($driver);

    expect($state->woocommerce?->toArray())->toBe(['version' => '10.1.0', 'db_version' => null, 'db_update_needed' => null, 'overridden_templates' => null])
        ->and($state->warnings)->toBe(["No s'ha pogut llegir l'estat de WooCommerce al lloc."])
        ->and($state->maintenance)->toBeTrue()
        ->and($state->cron)->not->toBeNull();
});

it('nulls every field of the snippet, with one warning, when it fails or prints no JSON', function (): void {
    $failing = stateDriver();
    $failing->failingCommands = ['eval'];
    $broken = stateDriver();
    $broken->snippetOutput = 'Fatal error: boom';

    foreach ([$failing, $broken] as $driver) {
        $state = readState($driver);

        expect($state->woocommerce?->toArray())->toBe(['version' => '10.1.0', 'db_version' => null, 'db_update_needed' => null, 'overridden_templates' => null])
            ->and($state->cron)->toBeNull()
            ->and($state->maintenance)->toBeNull()
            ->and($state->diskFreeBytes)->toBe(10_000_000_000)
            ->and($state->warnings)->toHaveCount(1)
            ->and($state->warnings[0])->toContain("l'estat previ del lloc");
    }
});

it('counts overdue cron events past the grace period with the site clock', function (): void {
    $driver = stateDriver();
    $driver->cronEvents = [
        ['hook' => 'future', 'timestamp' => STATE_NOW + 3600],
        ['hook' => 'grace', 'timestamp' => STATE_NOW - 599],
        ['hook' => 'late', 'timestamp' => STATE_NOW - 900],
        ['hook' => 'late-too', 'timestamp' => STATE_NOW - 900],
        ['hook' => 'older', 'timestamp' => STATE_NOW - 7200],
    ];

    expect(readState($driver)->cron?->toArray())->toBe(['disabled' => false, 'overdue_events' => 3, 'oldest_overdue_seconds' => 7200]);
});

it('reports a healthy and a disabled cron', function (): void {
    $driver = stateDriver();
    $driver->cronDisabled = true;

    expect(readState($driver)->cron?->toArray())->toBe(['disabled' => true, 'overdue_events' => 0, 'oldest_overdue_seconds' => null]);
});

it('treats a fresh .maintenance file as maintenance and an old or timestampless one as not', function (): void {
    $off = stateDriver();
    $fresh = stateDriver();
    $fresh->maintenance = true;
    $edge = stateDriver();
    $edge->maintenance = true;
    $edge->maintenanceUpgrading = STATE_NOW - 599;
    $stale = stateDriver();
    $stale->maintenance = true;
    $stale->maintenanceUpgrading = STATE_NOW - 600;
    $noTimestamp = stateDriver();
    $noTimestamp->maintenance = true;
    $noTimestamp->maintenanceWithoutTimestamp = true;

    expect(readState($off)->maintenance)->toBeFalse()
        ->and(readState($fresh)->maintenance)->toBeTrue()
        ->and(readState($edge)->maintenance)->toBeTrue()
        ->and(readState($stale)->maintenance)->toBeFalse()
        ->and(readState($noTimestamp)->maintenance)->toBeFalse();
});

it('reads everything with a single WP-CLI process', function (): void {
    $driver = stateDriver();

    readState($driver);

    expect($driver->wpCalls)->toBe([['eval', SiteState::SNIPPET]]);
});

it('reads the free disk space and warns when it cannot', function (): void {
    $ok = new FakeDriver(freeDisk: 5_000_000_000);
    $bad = stateDriver();
    $bad->diskFails = true;

    $state = readState($bad);

    expect(readState($ok)->diskFreeBytes)->toBe(5_000_000_000)
        ->and($state->diskFreeBytes)->toBeNull()
        ->and($state->warnings[0])->toContain("l'espai lliure al disc")
        ->and($state->maintenance)->toBeFalse();
});

it('redacts secrets in warnings', function (): void {
    $redactor = new SecretRedactor;
    $redactor->register('hunter2hunter2');
    $driver = stateDriver();
    $driver->wpFailure = new RemoteCommandFailed('failed with hunter2hunter2 in the command');

    $warnings = readState($driver, siteState($redactor))->warnings;

    expect($warnings)->not->toBe([])
        ->and(implode(' ', $warnings))->not->toContain('hunter2hunter2');
});

it('lets a lost connection propagate', function (): void {
    $driver = stateDriver();
    $driver->wpFailure = new ConnectionFailed('connexió perduda');

    expect(fn () => readState($driver))->toThrow(ConnectionFailed::class);
});

it('keeps the snippet constant and PHP 7.4 friendly', function (): void {
    $snippet = SiteState::SNIPPET;

    expect(str_contains($snippet, 'JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR'))->toBeTrue()
        ->and(str_contains($snippet, '_cleanup_header_comment'))->toBeTrue()
        ->and(str_contains($snippet, "is_callable(array('WC_Install', 'needs_db_update'))"))->toBeTrue()
        ->and(str_contains($snippet, 'match ('))->toBeFalse();
});

it('parses command output like the shared reader', function (): void {
    $reader = new WpCliReader(new SecretRedactor);

    expect($reader->lastLine("Notice: x\n\n1\n\n"))->toBe('1')
        ->and($reader->lastLine(" \n"))->toBeNull()
        ->and($reader->json("Warning\n{\"a\":1}\n"))->toBe(['a' => 1])
        ->and($reader->json('nope'))->toBeNull()
        ->and($reader->text(''))->toBeNull()
        ->and($reader->text('x'))->toBe('x');
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
    $run = Run::factory()->create(['type' => RunType::Inventory]);
    $step = static fn (): StepOutcome => (new InventoryStep(new SiteInventory(null, null, siteState()), static fn () => $driver, app(RunAuthorization::class), 60))->run(new StepContext($run, false));

    $quiet = $step();

    $driver->maintenance = true;
    $driver->wcDbUpdateNeeded = true;
    $driver->wcOverriddenTemplates = [['file' => 'storefront/woocommerce/cart/cart.php', 'theme_version' => '3.8.0', 'core_version' => '10.1.0']];
    $driver->cronEvents = [['hook' => 'late', 'timestamp' => time() - 7200]];
    $driver->diskFails = true;
    $loud = $step();

    expect(str_contains($quiet->summary, 'manteniment') || str_contains($quiet->summary, 'avisos'))->toBeFalse()
        ->and(str_contains($loud->summary, 'manteniment actiu'))->toBeTrue()
        ->and(str_contains($loud->summary, "BD de WooCommerce pendent d'actualitzar"))->toBeTrue()
        ->and(str_contains($loud->summary, '1 plantilles sobreescrites (1 desfasades)'))->toBeTrue()
        ->and(str_contains($loud->summary, '1 esdeveniments de cron endarrerits'))->toBeTrue()
        ->and(str_contains($loud->summary, "1 avisos de l'estat previ"))->toBeTrue();
});
