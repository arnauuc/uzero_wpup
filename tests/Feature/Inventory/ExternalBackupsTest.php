<?php

declare(strict_types=1);

use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Inventory\ExternalBackups;
use App\Domain\Inventory\SiteInventory;
use App\Enums\ComponentType;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Tests\Support\FakeDriver;

const EXTERNAL_NOW = 1_800_000_000;

function externalBackupDriver(): FakeDriver
{
    $driver = new FakeDriver;
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'updraftplus', '1.24.1', true));
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'backwpup', '4.0.0', true));
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'duplicator', '1.5.0', false));

    return $driver;
}

/**
 * @return array{plugins: list<array{slug: string, name: string, active: bool, last_backup_at: string|null}>, warnings: list<string>}
 */
function readBackups(FakeDriver $driver): array
{
    return app(ExternalBackups::class)->read($driver, $driver->installedComponents(), CarbonImmutable::createFromTimestampUTC(EXTERNAL_NOW))->toArray();
}

it('detects backup plugins by slug and reads the UpdraftPlus date', function (): void {
    $driver = externalBackupDriver();
    $driver->options['updraft_last_backup'] = (string) json_encode(['nonce' => 'abc', 'backup_time' => EXTERNAL_NOW - 3600, 'success' => 1]);

    $result = readBackups($driver);

    expect($result['warnings'])->toBe([])
        ->and(array_column($result['plugins'], 'slug'))->toBe(['backwpup', 'duplicator', 'updraftplus'])
        ->and($result['plugins'][2])->toBe(['slug' => 'updraftplus', 'name' => 'UpdraftPlus', 'active' => true, 'last_backup_at' => CarbonImmutable::createFromTimestampUTC(EXTERNAL_NOW - 3600)->toIso8601String()])
        ->and($result['plugins'][0]['last_backup_at'])->toBeNull()
        ->and($result['plugins'][1]['active'])->toBeFalse()
        ->and($driver->wpCalls)->toBe([['option', 'get', 'updraft_last_backup', '--format=json']]);
});

it('does not read the date of an inactive plugin', function (): void {
    $driver = new FakeDriver;
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'updraftplus', '1.24.1', false));

    expect(readBackups($driver)['plugins'][0]['last_backup_at'])->toBeNull()->and($driver->wpCalls)->toBe([]);
});

it('lists nothing for a site without backup plugins', function (): void {
    expect(readBackups(new FakeDriver))->toBe(['plugins' => [], 'warnings' => []]);
});

it('turns an untrustworthy answer into a warning and a null date', function (string $output): void {
    $driver = externalBackupDriver();
    $driver->options['updraft_last_backup'] = $output;

    $result = readBackups($driver);

    expect($result['plugins'][2]['last_backup_at'])->toBeNull()
        ->and($result['plugins'][2]['active'])->toBeTrue()
        ->and($result['warnings'])->toHaveCount(1);
})->with([
    'not json' => 'Array',
    'no key' => '{"nonce":"x"}',
    'text time' => '{"backup_time":"yesterday"}',
    'negative' => '{"backup_time":-5}',
    'far future' => '{"backup_time":'.(EXTERNAL_NOW + 10 * 86400).'}',
]);

it('warns when the option cannot be read and keeps the inventory going', function (): void {
    $result = readBackups(externalBackupDriver());

    expect($result['warnings'])->toHaveCount(1)->and($result['plugins'])->toHaveCount(3);
});

it('lets a lost connection propagate', function (): void {
    $driver = externalBackupDriver();
    $driver->wpFailure = new ConnectionFailed('caiguda');

    expect(fn () => readBackups($driver))->toThrow(ConnectionFailed::class);
});

it('stores the detection in the snapshot environment', function (): void {
    $driver = externalBackupDriver();
    $driver->options['updraft_last_backup'] = (string) json_encode(['backup_time' => time() - 60]);

    $snapshot = app(SiteInventory::class)->take(Site::factory()->create(), $driver, null, false);

    /** @var array{plugins: list<array{slug: string, last_backup_at: string|null}>} $backups */
    $backups = $snapshot->environment['external_backups'] ?? ['plugins' => []];
    expect(array_column($backups['plugins'], 'slug'))->toBe(['backwpup', 'duplicator', 'updraftplus'])
        ->and($backups['plugins'][2]['last_backup_at'])->toBeString();
});
