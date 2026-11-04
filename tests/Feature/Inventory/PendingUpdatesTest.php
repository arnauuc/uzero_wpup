<?php

declare(strict_types=1);

use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Inventory\PendingUpdates;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\ComponentType;
use App\Models\Site;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeDriver;

function pendingUpdates(?Repository $cache = null, int $maxLookups = 5): PendingUpdates
{
    return new PendingUpdates(
        app(Factory::class),
        $cache ?? new Repository(new ArrayStore),
        app(SecretRedactor::class),
        'https://api.wordpress.org/plugins/info/1.2/',
        'https://api.wordpress.org/themes/info/1.2/',
        5,
        10,
        $maxLookups,
        3600,
        600,
    );
}

function pendingDriver(): FakeDriver
{
    $driver = new FakeDriver;
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'premium-thing', '2.0.0'));
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'nolicence', '1.0.0'));
    $driver->addComponent(new ComponentVersion(ComponentType::Plugin, 'hello', '1.6'));
    $driver->transients['update_plugins'] = [
        'last_checked' => time(),
        'response' => [
            'woocommerce/woocommerce.php' => [
                'id' => 'w.org/plugins/woocommerce', 'slug' => 'woocommerce', 'new_version' => '10.2.0',
                'package' => 'https://downloads.wordpress.org/plugin/woocommerce.10.2.0.zip',
            ],
            'premium-thing/premium-thing.php' => [
                'slug' => 'premium-thing', 'new_version' => '3.0.0', 'package' => 'https://user:SECRET-KEY@shop.test/download/SECRET-KEY/x.zip?license=SECRET-KEY',
                'requires_php' => '7.4', 'requires' => '6.0', 'wc_requires' => '9.0',
            ],
            'nolicence/nolicence.php' => ['slug' => 'nolicence', 'new_version' => '2.0.0', 'package' => ''],
            'hello.php' => ['id' => 'w.org/plugins/hello-dolly', 'slug' => 'hello-dolly', 'new_version' => '1.7.2', 'package' => 'https://downloads.wordpress.org/plugin/hello-dolly.1.7.2.zip', 'requires' => '6.0', 'requires_php' => '7.0'],
        ],
    ];
    $driver->transients['update_themes'] = [
        'last_checked' => time(),
        'response' => ['storefront' => ['theme' => 'storefront', 'url' => 'https://wordpress.org/themes/storefront/', 'new_version' => '4.7.0', 'package' => 'https://downloads.wordpress.org/theme/storefront.4.7.0.zip']],
    ];
    $driver->coreUpdates = [['version' => '6.9.0', 'update_type' => 'major', 'package_url' => 'https://downloads.wordpress.org/release/wordpress-6.9.0.zip']];

    return $driver;
}

/**
 * @return array<string, array<string, mixed>>
 */
function pendingBySlug(PendingUpdates $service, FakeDriver $driver): array
{
    $by = [];
    foreach ($service->read($driver, $driver->installedComponents(), time())->updates as $update) {
        $by[$update->slug] = $update->toArray();
    }

    return $by;
}

it('combines site transients with WordPress.org requirements only where they are missing', function (): void {
    Http::fake([
        'api.wordpress.org/plugins/*' => Http::response(['slug' => 'woocommerce', 'requires' => '6.7', 'requires_php' => '7.4']),
        'api.wordpress.org/themes/*' => Http::response(['slug' => 'storefront', 'requires' => '6.5', 'requires_php' => '7.4']),
    ]);
    $driver = pendingDriver();

    $result = pendingUpdates()->read($driver, $driver->installedComponents(), time());
    $by = pendingBySlug(pendingUpdates(), $driver);

    expect($result->warnings)->toBe([])
        ->and($by['woocommerce'])->toMatchArray(['type' => 'woocommerce', 'wporg_slug' => 'woocommerce', 'current_version' => '10.1.0', 'new_version' => '10.2.0', 'requires_php' => '7.4', 'requires_wp' => '6.7', 'requires_wc' => null, 'source' => 'wordpress.org', 'licensed' => true, 'package_host' => 'downloads.wordpress.org'])
        ->and($by['storefront'])->toMatchArray(['type' => 'theme', 'requires_wp' => '6.5', 'source' => 'wordpress.org'])
        ->and($by['premium-thing'])->toMatchArray(['type' => 'plugin', 'source' => 'transient', 'requires_php' => '7.4', 'requires_wp' => '6.0', 'requires_wc' => '9.0', 'licensed' => true])
        ->and($by['hello'])->toMatchArray(['wporg_slug' => 'hello-dolly', 'current_version' => '1.6', 'requires_php' => '7.0'])
        ->and($by['wordpress'])->toMatchArray(['type' => 'core', 'current_version' => '6.8.3', 'new_version' => '6.9.0']);
    // Pooled: woocommerce and storefront, once each per read; hello and premium carry their requirements.
    Http::assertSentCount(4);
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'hello-dolly') || str_contains($request->url(), 'premium'));
});

it('caches the WordPress.org answers per slug and version', function (): void {
    Http::fake(['api.wordpress.org/*' => Http::response(['requires' => '6.7', 'requires_php' => '7.4'])]);
    $driver = pendingDriver();
    $service = pendingUpdates();

    $service->read($driver, $driver->installedComponents(), time());
    $service->read($driver, $driver->installedComponents(), time());

    Http::assertSentCount(2);
});

it('marks premium packages without download URL as unlicensed', function (): void {
    Http::fake();
    $by = pendingBySlug(pendingUpdates(), pendingDriver());

    expect($by['nolicence'])->toMatchArray(['licensed' => false, 'package_host' => null, 'requires_php' => null]);
});

it('never stores a package URL or its licence', function (): void {
    Http::fake();
    Log::spy();
    $driver = pendingDriver();

    $snapshot = (new SiteInventory(null, pendingUpdates()))->take(Site::factory()->create(), $driver);
    $stored = json_encode([$snapshot->pending_updates, $snapshot->environment]);
    $premium = array_values(array_filter($snapshot->pending_updates, static fn ($u): bool => is_array($u) && $u['slug'] === 'premium-thing'))[0];

    expect($premium['package_host'])->toBe('shop.test')
        ->and($premium['licensed'])->toBeTrue()
        ->and($premium)->not->toHaveKey('package_url')
        ->and(str_contains((string) $stored, 'SECRET-KEY'))->toBeFalse();
});

it('keeps transient data, warns and logs when WordPress.org fails', function (): void {
    Http::fake(['api.wordpress.org/*' => Http::response('boom', 500)]);
    $log = Log::spy();
    $driver = pendingDriver();

    $result = pendingUpdates()->read($driver, $driver->installedComponents(), time());

    expect($result->updates)->toHaveCount(6)
        ->and($result->warnings)->not->toBe([]);
    $log->shouldHaveReceived('warning')->atLeast()->once();
});

it('caps the WordPress.org lookups', function (): void {
    Http::fake(['api.wordpress.org/*' => Http::response(['requires' => '6.0', 'requires_php' => '7.4'])]);
    $driver = pendingDriver();

    $result = pendingUpdates(null, 1)->read($driver, $driver->installedComponents(), time());

    Http::assertSentCount(1);
    expect(implode(' ', $result->warnings))->toContain('límit');
});

it('treats a transient that is not set (exit 0, empty output) as no updates without warnings', function (): void {
    Http::fake();
    $driver = new FakeDriver;

    expect(pendingUpdates()->read($driver, $driver->installedComponents(), time())->warnings)->toBe([]);
});

it('warns about stale transients and failing commands', function (): void {
    Http::fake();
    $driver = new FakeDriver;
    $driver->transients['update_plugins'] = ['last_checked' => 1000, 'response' => []];

    $stale = pendingUpdates()->read($driver, $driver->installedComponents(), 1000 + 7200);
    expect($stale->updates)->toBe([])->and($stale->warnings)->toHaveCount(1);

    $driver->transientError = 'Error: boom';
    $driver->coreCheckFails = true;
    $failed = pendingUpdates()->read($driver, $driver->installedComponents(), time());
    expect($failed->warnings)->toHaveCount(3);
});

it('reports no core updates when WP-CLI answers an empty JSON list', function (): void {
    $driver = new FakeDriver;

    expect(pendingUpdates()->read($driver, $driver->installedComponents(), time())->updates)->toBe([]);
});

it('drops transient entries of components that are not installed, with a warning', function (): void {
    Http::fake();
    $driver = pendingDriver();
    $driver->transients['update_plugins'] = ['response' => ['ghost/ghost.php' => ['slug' => 'ghost', 'new_version' => '9', 'package' => '']]];

    $result = pendingUpdates()->read($driver, $driver->installedComponents(), time());

    expect(array_map(static fn ($u): string => $u->slug, $result->updates))->not->toContain('ghost')
        ->and(implode(' ', $result->warnings))->toContain('ghost');
});

it('lets a lost connection fail the read but turns a failed command into a warning', function (): void {
    $driver = new FakeDriver;
    $driver->wpFailure = new ConnectionFailed('connexió perduda');

    expect(fn () => pendingUpdates()->read($driver, $driver->installedComponents(), time()))->toThrow(ConnectionFailed::class);

    $driver->wpFailure = new RemoteCommandFailed('wp --password=hunter2 ha fallat');
    $result = pendingUpdates()->read($driver, $driver->installedComponents(), time());

    expect($result->warnings)->not->toBe([])
        ->and(implode(' ', $result->warnings))->not->toContain('hunter2');
});
