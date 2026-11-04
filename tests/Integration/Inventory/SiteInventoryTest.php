<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Ssh\SshDriverFactory;
use App\Domain\Inventory\PendingUpdates;
use App\Domain\Inventory\SiteInventory;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures;

/*
 * Inventory against the Docker test sites (make fixtures-up). wp80 is SFTP-only
 * and cannot be read by SshDriver.
 */

foreach (['wp74' => '6.6.2', 'wp82' => null, 'wp83' => null] as $fixture => $core) {
    if (! Fixtures::up($fixture)) {
        it("takes an inventory of {$fixture}")->skip('Llocs de prova aturats: make fixtures-up');

        continue;
    }

    it("takes an inventory of {$fixture}", function () use ($fixture, $core): void {
        $site = Fixtures::site($fixture);
        $driver = app(SshDriverFactory::class)->forSite($site, AuditActor::system('tests'));

        $snapshot = (new SiteInventory)->take($site, $driver);

        $plugins = [];
        foreach ($snapshot->plugins as $plugin) {
            if (is_array($plugin) && is_string($plugin['slug'] ?? null) && is_string($plugin['version'] ?? null)) {
                $plugins[$plugin['slug']] = $plugin['version'];
            }
        }

        expect(trim($driver->wp(['core', 'version'])->stdout))->toBe($snapshot->core_version)
            ->and($core === null || $snapshot->core_version === $core)->toBeTrue()
            ->and($plugins)->toHaveKeys(['woocommerce', 'classic-editor', 'hello-dolly'])
            ->and($plugins['woocommerce'])->not->toBe('')
            ->and($snapshot->php_version)->not->toBe('')
            ->and($snapshot->themes)->not->toBe([]);

        if ($fixture === 'wp82') {
            expect($snapshot->db_server_version)->toStartWith('8.0');
        }
    });
}

/*
 * Pending updates read from a real site transient. The transient is forced and
 * removed afterwards; WordPress.org is faked (no real network in tests).
 */
foreach (['wp74', 'wp83'] as $fixture) {
    if (! Fixtures::up($fixture)) {
        it("reads the pending updates transient of {$fixture}")->skip('Llocs de prova aturats: make fixtures-up');

        continue;
    }

    it("reads the pending updates transient of {$fixture}", function () use ($fixture): void {
        Http::fake(['api.wordpress.org/*' => Http::response(['requires' => '6.0', 'requires_php' => '7.4'])]);
        $site = Fixtures::site($fixture);
        $driver = app(SshDriverFactory::class)->forSite($site, AuditActor::system('tests'));
        // Start from the site's own transient (WordPress rebuilds one whose
        // "checked" list does not match the installed plugins) and replace its response.
        $installed = $driver->installedComponents();
        $current = json_decode($driver->wp(['transient', 'get', 'update_plugins', '--network', '--format=json'])->stdout, true);
        $current = is_array($current) ? $current : [];
        $current['last_checked'] = time();
        $current['response'] = [
            'hello-dolly/hello.php' => ['id' => 'w.org/plugins/hello-dolly', 'slug' => 'hello-dolly', 'new_version' => '99.0', 'package' => 'https://downloads.wordpress.org/plugin/hello-dolly.99.0.zip?k=secret'],
            'premium/premium.php' => ['slug' => 'premium', 'new_version' => '2.0', 'package' => ''],
        ];
        $value = json_encode($current, JSON_THROW_ON_ERROR);

        try {
            $driver->wp(['eval', 'set_site_transient("update_plugins", json_decode('.var_export($value, true).', false), 3600);']);

            // Not through SiteInventory::take(): listing the plugins again lets WordPress
            // rebuild the transient between forcing it and reading it.
            $result = app(PendingUpdates::class)->read($driver, $installed, time());
            $bySlug = [];
            foreach ($result->updates as $update) {
                $bySlug[$update->slug] = $update->toArray();
            }

            expect($bySlug['hello-dolly'])->toMatchArray(['new_version' => '99.0', 'package_url' => 'https://downloads.wordpress.org/plugin/hello-dolly.99.0.zip', 'package_has_query' => true, 'requires_php' => '7.4', 'licensed' => true])
                ->and($bySlug['premium']['licensed'])->toBeFalse();
        } finally {
            $driver->wp(['transient', 'delete', 'update_plugins', '--network']);
        }
    });

    it("stores the real pending updates of {$fixture} in the snapshot", function () use ($fixture): void {
        Http::fake(['api.wordpress.org/*' => Http::response(['requires' => '6.0', 'requires_php' => '7.4'])]);
        $site = Fixtures::site($fixture);
        $driver = app(SshDriverFactory::class)->forSite($site, AuditActor::system('tests'));

        $snapshot = (new SiteInventory(null, app(PendingUpdates::class)))->take($site, $driver, null, false);

        // Whatever the site has pending (depends on the date), each row has the full shape.
        foreach ($snapshot->pending_updates as $update) {
            expect($update)->toBeArray()->toHaveKeys(['type', 'slug', 'current_version', 'new_version', 'package_url', 'package_has_query', 'requires_php', 'requires_wp', 'requires_wc', 'source', 'licensed']);
        }
        expect($snapshot->environment['updates'] ?? null)->toHaveKey('warnings');
    });
}
