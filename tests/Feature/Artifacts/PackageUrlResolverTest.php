<?php

declare(strict_types=1);

use App\Domain\Artifacts\ArtifactException;
use App\Domain\Artifacts\ArtifactStore;
use App\Domain\Artifacts\PackageArtifacts;
use App\Domain\Artifacts\PackageSource;
use App\Domain\Artifacts\PackageUrlGuard;
use App\Domain\Artifacts\PackageUrlResolver;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Inventory\PendingUpdate;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\ComponentType;
use App\Models\Run;
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

it('builds the core package URL from config with the site locale, never from the site', function (?string $wplang, string $url): void {
    $driver = new FakeDriver;
    if ($wplang !== null) {
        $driver->options['WPLANG'] = $wplang;
    }
    $driver->coreUpdates = [['version' => '6.9.0', 'update_type' => 'minor', 'package_url' => 'https://evil.example.com/wordpress-6.9.zip']];

    $source = app(PackageUrlResolver::class)->resolve($driver, pendingFor('core', 'wordpress', '6.9.0', PendingUpdate::SOURCE_WORDPRESS_ORG));

    expect($source->url->reveal())->toBe($url)->and($source->type)->toBe(ComponentType::Core);
})->with([
    'localized' => ['ca', 'https://downloads.wordpress.org/release/ca/wordpress-6.9.0.zip'],
    'with region' => ['pt_BR', 'https://downloads.wordpress.org/release/pt_BR/wordpress-6.9.0.zip'],
    'english' => [null, 'https://downloads.wordpress.org/release/wordpress-6.9.0.zip'],
    'not a locale' => ['../../evil', 'https://downloads.wordpress.org/release/wordpress-6.9.0.zip'],
]);

it('lets a lost connection propagate and turns other site failures into noPackage', function (): void {
    $driver = new FakeDriver;
    $driver->wpFailure = new ConnectionFailed('connexió perduda');
    $premium = pendingFor('plugin', 'premium-thing', '2.1.0', PendingUpdate::SOURCE_TRANSIENT);
    expect(fn () => app(PackageUrlResolver::class)->resolve($driver, $premium))->toThrow(ConnectionFailed::class);

    $driver->wpFailure = new RemoteCommandFailed('wp ha fallat');
    expect(fn () => app(PackageUrlResolver::class)->resolve($driver, $premium))->toThrow(ArtifactException::class, 'premium-thing');
});

it('reuses the artifact the run already holds without asking the site', function (): void {
    config(['wpup.artifacts.dir' => sys_get_temp_dir().'/wpup-artifacts-'.bin2hex(random_bytes(6))]);
    $run = Run::factory()->create();
    $store = app(ArtifactStore::class);
    $temporary = $store->temporaryFile($run->id);
    file_put_contents($temporary, 'zip');
    $source = PackageSource::create(ComponentType::Plugin, 'premium-thing', '2.1.0', 'https://shop.example.com/p.zip', app(PackageUrlGuard::class));
    $stored = $store->commit($run->id, $source, $temporary, hash('sha256', 'zip'), 3);
    $driver = new FakeDriver;
    $driver->wpFailure = new ConnectionFailed('el lloc no respon');

    try {
        expect(app(PackageArtifacts::class)->obtain($run->id, fn () => $driver, pendingFor('plugin', 'premium-thing', '2.1.0', PendingUpdate::SOURCE_TRANSIENT)))->toEqual($stored)
            ->and($driver->wpCalls)->toBe([]);
    } finally {
        exec('rm -rf '.escapeshellarg(artifactsRoot()));
    }
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
