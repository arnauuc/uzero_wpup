<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Ssh\SshDriverFactory;
use App\Domain\Inventory\SiteInventory;
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
