<?php

declare(strict_types=1);

use App\Domain\Artifacts\ArtifactException;
use App\Domain\Artifacts\PackageUrlResolver;
use App\Domain\Inventory\PendingUpdate;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\ComponentType;
use Tests\Support\FakeDriver;

function pendingFor(string $type, string $slug, string $version, string $source, ?string $wporg = null): PendingUpdate
{
    return new PendingUpdate($type, $slug, $wporg, '1.0.0', $version, 'example.com', null, null, null, $source, true);
}

beforeEach(fn () => app(SecretRedactor::class)->reset());

it('builds WordPress.org URLs from the slug, without asking the site', function (): void {
    $driver = new FakeDriver;
    $resolver = app(PackageUrlResolver::class);

    $plugin = $resolver->resolve($driver, pendingFor('woocommerce', 'woocommerce', '10.2.0', PendingUpdate::SOURCE_WORDPRESS_ORG));
    $theme = $resolver->resolve($driver, pendingFor('theme', 'storefront', '4.7.0', PendingUpdate::SOURCE_WORDPRESS_ORG));

    expect($plugin->url->reveal())->toBe('https://downloads.wordpress.org/plugin/woocommerce.10.2.0.zip')
        ->and($plugin->type)->toBe(ComponentType::Woocommerce)
        ->and($theme->url->reveal())->toBe('https://downloads.wordpress.org/theme/storefront.4.7.0.zip')
        ->and($driver->wpCalls)->toBe([]);
});

it('reads premium package URLs from the site transient and registers their tokens', function (): void {
    $driver = new FakeDriver;
    $url = 'https://shop.example.com/?download=premium&key=9b1c7e2a5d3f8e6c';
    $driver->transients['update_plugins'] = ['response' => [
        'premium-thing/premium-thing.php' => ['new_version' => '2.1.0', 'package' => $url],
        'other/other.php' => ['new_version' => '3.0.0', 'package' => 'https://x.example.com/other.zip'],
    ]];

    $source = app(PackageUrlResolver::class)->resolve($driver, pendingFor('plugin', 'premium-thing', '2.1.0', PendingUpdate::SOURCE_TRANSIENT));

    expect($source->url->reveal())->toBe($url)->and($source->host())->toBe('shop.example.com')
        ->and(app(SecretRedactor::class)->redact("failed: {$url} 9b1c7e2a5d3f8e6c"))->not->toContain('9b1c7e2a5d3f8e6c');
});

it('takes the core package URL of the planned version from check-update', function (): void {
    $driver = new FakeDriver;
    $driver->coreUpdates = [['version' => '6.9.0', 'update_type' => 'minor', 'package_url' => 'https://downloads.wordpress.org/release/ca/wordpress-6.9.zip']];

    $source = app(PackageUrlResolver::class)->resolve($driver, pendingFor('core', 'wordpress', '6.9.0', PendingUpdate::SOURCE_WORDPRESS_ORG));

    expect($source->url->reveal())->toBe('https://downloads.wordpress.org/release/ca/wordpress-6.9.zip')->and($source->type)->toBe(ComponentType::Core);
});

it('fails without quoting the site output when the package is gone, changed or unlicensed', function (array $entry, string $error): void {
    $driver = new FakeDriver;
    $driver->transients['update_plugins'] = ['response' => $entry];

    expect(fn () => app(PackageUrlResolver::class)->resolve($driver, pendingFor('plugin', 'premium-thing', '2.1.0', PendingUpdate::SOURCE_TRANSIENT)))
        ->toThrow(function (ArtifactException $e) use ($error): void {
            expect($e->getMessage())->toContain($error)
                ->and(str_contains($e->getMessage(), 's3cr3t-licence-0001'))->toBeFalse();
        });
})->with([
    'gone' => [[], 'ja no té aquesta actualització'],
    'newer version' => [['premium-thing/premium-thing.php' => ['new_version' => '2.2.0', 'package' => 'https://x.example.com/?k=s3cr3t-licence-0001']], 'una altra versió'],
    'no licence' => [['premium-thing/premium-thing.php' => ['new_version' => '2.1.0', 'package' => '']], 'llicència'],
    'plain http' => [['premium-thing/premium-thing.php' => ['new_version' => '2.1.0', 'package' => 'http://x.example.com/?k=s3cr3t-licence-0001']], 'no és HTTPS'],
]);
