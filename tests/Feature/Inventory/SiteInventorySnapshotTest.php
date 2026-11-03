<?php

declare(strict_types=1);

use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Inventory\SiteInventory;
use App\Enums\ComponentType;
use App\Enums\PhpVersionSource;
use App\Models\Site;
use Tests\Support\FakeDriver;

/**
 * @param  array<int, mixed>  $rows
 * @return array<mixed>|null
 */
function bySlug(array $rows, string $slug): ?array
{
    foreach ($rows as $row) {
        if (is_array($row) && ($row['slug'] ?? null) === $slug) {
            return $row;
        }
    }

    return null;
}

it('stores core, php and database versions with the configured php source', function (): void {
    $site = Site::factory()->create();

    $snapshot = (new SiteInventory)->take($site, new FakeDriver('6.8.3', '8.3.14'));

    expect($snapshot->site_id)->toBe($site->id)
        ->and($snapshot->run_id)->toBeNull()
        ->and($snapshot->core_version)->toBe('6.8.3')
        ->and($snapshot->php_version)->toBe('8.3.14')
        ->and($snapshot->php_source)->toBe(PhpVersionSource::Configured)
        ->and($snapshot->db_server_version)->toBe('10.11.9-MariaDB')
        ->and($snapshot->pending_updates)->toBe([])
        ->and($snapshot->environment)->toBe([]);
});

it('separates plugins, themes, drop-ins and mu-plugins and keeps inactive ones', function (): void {
    $driver = new FakeDriver;
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'hello-dolly', '1.7.2', false, 'Hello Dolly'));
    $driver->addComponent(new ComponentVersion(ComponentType::Dropin, 'object-cache.php', '2.0.0'));
    $driver->addComponent(new ComponentVersion(ComponentType::MuPlugin, 'wpup-guard', '1.0.0'));
    $driver->addComponent(new ComponentVersion(ComponentType::Theme, 'twentytwentyfive', '1.0'));

    $snapshot = (new SiteInventory)->take(Site::factory()->create(), $driver);

    expect(bySlug($snapshot->plugins, 'hello-dolly'))->toMatchArray(['version' => '1.7.2', 'active' => false, 'type' => 'plugin'])
        ->and(bySlug($snapshot->plugins, 'woocommerce'))->toMatchArray(['version' => '10.1.0', 'type' => 'woocommerce'])
        ->and(bySlug($snapshot->plugins, 'classic-editor'))->toMatchArray(['active' => true])
        ->and(bySlug($snapshot->plugins, 'object-cache.php'))->toBeNull()
        ->and($snapshot->dropins)->toHaveCount(1)
        ->and(bySlug($snapshot->dropins, 'object-cache.php'))->toMatchArray(['version' => '2.0.0'])
        ->and($snapshot->mu_plugins)->toHaveCount(1)
        ->and(bySlug($snapshot->mu_plugins, 'wpup-guard'))->not->toBeNull();
});

it('marks the active theme, its parent and the inactive ones', function (): void {
    $driver = new FakeDriver;
    $driver->dbServerVersion = '8.0.40';
    $driver->activeTheme = 'child';
    $driver->parentTheme = 'storefront';
    $driver->addComponent(new ComponentVersion(ComponentType::Theme, 'child', '1.0.0'));
    $driver->addComponent(new ComponentVersion(ComponentType::Theme, 'twentytwentyfive', '1.0'));

    $snapshot = (new SiteInventory)->take(Site::factory()->create(), $driver);

    expect(bySlug($snapshot->themes, 'child'))->toMatchArray(['status' => SiteInventory::THEME_ACTIVE])
        ->and(bySlug($snapshot->themes, 'storefront'))->toMatchArray(['status' => SiteInventory::THEME_PARENT])
        ->and(bySlug($snapshot->themes, 'twentytwentyfive'))->toMatchArray(['status' => SiteInventory::THEME_INACTIVE])
        ->and($snapshot->db_server_version)->toBe('8.0.40');
});
