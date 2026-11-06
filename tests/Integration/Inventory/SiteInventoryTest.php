<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Ssh\SshDriverFactory;
use App\Domain\Inventory\PendingUpdates;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Inventory\SiteState;
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
            'hello.php' => ['id' => 'w.org/plugins/hello-dolly', 'slug' => 'hello-dolly', 'new_version' => '99.0', 'package' => 'https://downloads.wordpress.org/plugin/hello-dolly.99.0.zip?k=secret'],
            'classic-editor/classic-editor.php' => ['slug' => 'classic-editor', 'new_version' => '2.0', 'package' => ''],
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

            expect($bySlug['hello'])->toMatchArray(['new_version' => '99.0', 'wporg_slug' => 'hello-dolly', 'package_host' => 'downloads.wordpress.org', 'requires_php' => '7.4', 'licensed' => true])
                ->and($bySlug['classic-editor']['licensed'])->toBeFalse();
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
            expect($update)->toBeArray()->toHaveKeys(['type', 'slug', 'current_version', 'new_version', 'wporg_slug', 'package_host', 'requires_php', 'requires_wp', 'requires_wc', 'source', 'licensed']);
        }
        expect($snapshot->environment['updates'] ?? null)->toHaveKey('warnings');
    });
}

/*
 * Pre-update state (UZEROWPUP-33) against the real sites. The theme template
 * override is created for the test and removed afterwards.
 */
foreach (['wp74', 'wp83'] as $fixture) {
    if (! Fixtures::up($fixture)) {
        it("reads the pre-update state of {$fixture}")->skip('Llocs de prova aturats: make fixtures-up');

        continue;
    }

    it("reads the pre-update state of {$fixture} and lists overridden theme templates", function () use ($fixture): void {
        $site = Fixtures::site($fixture);
        $driver = app(SshDriverFactory::class)->forSite($site, AuditActor::system('tests'));
        $theme = trim($driver->wp(['eval', 'echo get_stylesheet_directory();'])->stdout);
        $dir = "{$theme}/woocommerce/cart";
        $file = "{$dir}/cart.php";

        try {
            Fixtures::run($fixture, 'mkdir -p '.escapeshellarg($dir).' && printf %s '.escapeshellarg("<?php\n/** @version 1.0.0 */\n").' > '.escapeshellarg($file));

            $state = app(SiteState::class)->read($driver, $driver->installedComponents(), time());
        } finally {
            Fixtures::run($fixture, 'rm -f '.escapeshellarg($file).' && rmdir '.escapeshellarg($dir).' '.escapeshellarg("{$theme}/woocommerce").' 2>/dev/null; true');
        }

        $override = null;
        foreach ($state->woocommerce->overriddenTemplates ?? [] as $template) {
            if ($template->file === basename($theme).'/woocommerce/cart/cart.php') {
                $override = $template;
            }
        }

        expect($state->warnings)->toBe([])
            ->and($state->woocommerce?->version)->not->toBeNull()
            ->and($state->woocommerce?->dbVersion)->not->toBeNull()
            ->and($state->woocommerce?->dbUpdateNeeded)->toBeBool()
            // The one-line header is cleaned up like WooCommerce does ("1.0.0 */" -> "1.0.0").
            ->and($override?->themeVersion)->toBe('1.0.0')
            ->and($override?->outdated)->toBeTrue()
            ->and($override?->coreVersion)->not->toBeNull()
            ->and($state->cron)->not->toBeNull()
            ->and($state->maintenance)->toBeFalse()
            ->and($state->diskFreeBytes)->toBeGreaterThan(0);
    });

    it("sees a fresh and a stale .maintenance file of {$fixture}", function () use ($fixture): void {
        $site = Fixtures::site($fixture);
        $driver = app(SshDriverFactory::class)->forSite($site, AuditActor::system('tests'));
        $file = trim($driver->wp(['eval', 'echo ABSPATH;'])->stdout).'.maintenance';
        $read = static fn (): ?bool => app(SiteState::class)->read($driver, $driver->installedComponents(), time())->maintenance;

        try {
            $driver->setMaintenanceMode(true);
            $fresh = $read();
            Fixtures::run($fixture, 'printf %s '.escapeshellarg('<?php $upgrading = '.(time() - 3600).'; ?>').' > '.escapeshellarg($file));
            $stale = $read();
        } finally {
            $driver->setMaintenanceMode(false);
            // A stale file is not "active", so deactivating leaves it: remove it.
            Fixtures::run($fixture, 'rm -f '.escapeshellarg($file));
        }

        expect($fresh)->toBeTrue()
            ->and($stale)->toBeFalse()
            ->and($read())->toBeFalse();
    });
}
