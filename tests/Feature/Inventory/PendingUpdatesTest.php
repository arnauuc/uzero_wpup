<?php

declare(strict_types=1);

use App\Domain\Inventory\PendingUpdates;
use App\Domain\Inventory\SiteInventory;
use App\Models\Site;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeDriver;

function pendingUpdates(): PendingUpdates
{
    return new PendingUpdates(
        app(Factory::class),
        'https://api.wordpress.org/plugins/info/1.2/',
        'https://api.wordpress.org/themes/info/1.2/',
        5,
        10,
        2,
        3600,
    );
}

function pendingDriver(): FakeDriver
{
    $driver = new FakeDriver;
    $driver->transients['update_plugins'] = [
        'last_checked' => time(),
        'response' => [
            'woocommerce/woocommerce.php' => [
                'id' => 'w.org/plugins/woocommerce', 'slug' => 'woocommerce', 'new_version' => '10.2.0',
                'package' => 'https://downloads.wordpress.org/plugin/woocommerce.10.2.0.zip',
            ],
            'premium-thing/premium-thing.php' => [
                'slug' => 'premium-thing', 'new_version' => '3.0.0', 'package' => 'https://shop.test/dl.zip?license=SECRET-KEY&token=abc',
                'requires_php' => '7.4', 'requires' => '6.0', 'wc_requires' => '9.0',
            ],
            'nolicence/nolicence.php' => ['slug' => 'nolicence', 'new_version' => '2.0.0', 'package' => ''],
        ],
    ];
    $driver->transients['update_themes'] = [
        'last_checked' => time(),
        'response' => ['storefront' => ['id' => 'w.org/themes/storefront', 'theme' => 'storefront', 'new_version' => '4.7.0', 'package' => 'https://downloads.wordpress.org/theme/storefront.4.7.0.zip']],
    ];
    $driver->coreUpdates = [['version' => '6.9.0', 'update_type' => 'major', 'package_url' => 'https://downloads.wordpress.org/release/wordpress-6.9.0.zip']];

    return $driver;
}

it('combines site transients with WordPress.org requirements', function (): void {
    Http::fake([
        'api.wordpress.org/plugins/*' => Http::response(['slug' => 'woocommerce', 'requires' => '6.7', 'requires_php' => '7.4']),
        'api.wordpress.org/themes/*' => Http::response(['slug' => 'storefront', 'requires' => '6.5', 'requires_php' => '7.4']),
    ]);
    $driver = pendingDriver();

    $result = pendingUpdates()->read($driver, $driver->installedComponents(), time());
    $bySlug = [];
    foreach ($result->updates as $update) {
        $bySlug[$update->slug] = $update->toArray();
    }

    expect($result->warnings)->toBe([])
        ->and($bySlug['woocommerce'])->toMatchArray(['type' => 'woocommerce', 'current_version' => '10.1.0', 'new_version' => '10.2.0', 'requires_php' => '7.4', 'requires_wp' => '6.7', 'requires_wc' => null, 'source' => 'wordpress.org', 'licensed' => true])
        ->and($bySlug['storefront'])->toMatchArray(['type' => 'theme', 'requires_wp' => '6.5', 'source' => 'wordpress.org'])
        ->and($bySlug['premium-thing'])->toMatchArray(['type' => 'plugin', 'source' => 'transient', 'requires_php' => '7.4', 'requires_wp' => '6.0', 'requires_wc' => '9.0', 'licensed' => true])
        ->and($bySlug['wordpress'])->toMatchArray(['type' => 'core', 'current_version' => '6.8.3', 'new_version' => '6.9.0']);
    Http::assertSentCount(2);
});

it('marks premium packages without download URL as unlicensed', function (): void {
    Http::fake();
    $driver = pendingDriver();

    $result = pendingUpdates()->read($driver, $driver->installedComponents(), time());
    $nolicence = array_values(array_filter($result->updates, static fn ($u): bool => $u->slug === 'nolicence'))[0];

    expect($nolicence->licensed)->toBeFalse()
        ->and($nolicence->packageUrl)->toBeNull()
        ->and($nolicence->requiresPhp)->toBeNull();
});

it('strips the query string of package URLs and never logs them', function (): void {
    Http::fake();
    $log = Log::spy();
    $driver = pendingDriver();

    $snapshot = (new SiteInventory(null, pendingUpdates()))->take(Site::factory()->create(), $driver);
    $stored = (string) json_encode($snapshot->pending_updates);
    $environment = (string) json_encode($snapshot->environment);
    $premium = array_values(array_filter($snapshot->pending_updates, static fn ($u): bool => is_array($u) && $u['slug'] === 'premium-thing'))[0];

    expect($premium['package_url'])->toBe('https://shop.test/dl.zip')
        ->and($premium['package_has_query'])->toBeTrue()
        ->and(str_contains($stored, 'SECRET-KEY') || str_contains($stored, 'token'))->toBeFalse()
        ->and(str_contains($environment, 'SECRET-KEY'))->toBeFalse();
    $log->shouldNotHaveReceived('warning');
});

it('keeps transient data and warns when WordPress.org fails', function (): void {
    Http::fake(['api.wordpress.org/*' => Http::response('boom', 500)]);
    $driver = pendingDriver();

    $result = pendingUpdates()->read($driver, $driver->installedComponents(), time());

    expect($result->updates)->toHaveCount(5)
        ->and($result->warnings)->not->toBe([])
        ->and(implode(' ', $result->warnings))->not->toContain('downloads.wordpress.org');
});

it('caps the WordPress.org lookups', function (): void {
    Http::fake(['api.wordpress.org/*' => Http::response(['requires' => '6.0', 'requires_php' => '7.4'])]);
    $driver = pendingDriver();
    $driver->transients['update_plugins'] = ['last_checked' => time(), 'response' => [
        'a/a.php' => ['id' => 'w.org/plugins/a', 'slug' => 'a', 'new_version' => '2', 'package' => 'https://x.test/a.zip'],
        'b/b.php' => ['id' => 'w.org/plugins/b', 'slug' => 'b', 'new_version' => '2', 'package' => 'https://x.test/b.zip'],
        'c/c.php' => ['id' => 'w.org/plugins/c', 'slug' => 'c', 'new_version' => '2', 'package' => 'https://x.test/c.zip'],
    ]];

    $result = pendingUpdates()->read($driver, $driver->installedComponents(), time());

    Http::assertSentCount(2);
    expect(implode(' ', $result->warnings))->toContain('límit');
});

it('treats a missing transient as no updates and warns about a stale one', function (): void {
    Http::fake();
    $driver = new FakeDriver;

    expect(pendingUpdates()->read($driver, $driver->installedComponents(), time())->warnings)->toBe([]);

    $driver->transients['update_plugins'] = ['last_checked' => 1000, 'response' => []];
    $stale = pendingUpdates()->read($driver, $driver->installedComponents(), 1000 + 7200);

    expect($stale->updates)->toBe([])->and($stale->warnings)->toHaveCount(1);

    $driver->transientError = 'Error: boom';
    expect(pendingUpdates()->read($driver, $driver->installedComponents(), time())->warnings)->toHaveCount(2);
});
