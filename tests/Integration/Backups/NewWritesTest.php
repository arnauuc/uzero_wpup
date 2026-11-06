<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\AffectedRecord;
use App\Domain\Backups\NewWritesDetector;
use App\Domain\Backups\ShopWritesReader;
use App\Domain\Drivers\Ssh\SshDriver;
use App\Domain\Drivers\Ssh\SshDriverFactory;
use App\Enums\NewWritesStatus;
use Tests\Support\Fixtures;

/*
 * Order and user watermark (UZEROWPUP-58) against the Docker test sites, with
 * HPOS and with the classic posts storage of orders. Never real sites. Every
 * order, user and option the test writes is removed or put back.
 */

if (! Fixtures::up('wp83')) {
    it('detects the orders and users written since a watermark')->skip('Llocs de prova aturats: make fixtures-up');

    return;
}

/** Runs PHP with WordPress and WooCommerce loaded; its last line. */
function shopEval(SshDriver $driver, string $code): string
{
    $result = $driver->wp(['eval', $code]);
    expect($result->successful())->toBeTrue($result->stderr);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $result->stdout))));

    return $lines === [] ? '' : $lines[count($lines) - 1];
}

function shopOrder(SshDriver $driver, string $status): int
{
    return (int) shopEval($driver, '$o = wc_create_order(); $o->set_total(12.5); $o->set_status('.var_export($status, true).'); $o->save(); echo $o->get_id();');
}

it('detects the orders and users written since a watermark', function (string $fixture, bool $hpos): void {
    $driver = app(SshDriverFactory::class)->forSite(Fixtures::site($fixture), AuditActor::system('tests'));
    $original = shopEval($driver, "echo get_option('woocommerce_custom_orders_table_enabled', 'no');");
    [$orders, $users] = [[], []];

    try {
        shopEval($driver, "update_option('woocommerce_custom_orders_table_enabled', '".($hpos ? 'yes' : 'no')."'); echo 'ok';");
        $orders[] = $old = shopOrder($driver, 'pending');
        sleep(1); // dates have a resolution of one second
        $stored = app(ShopWritesReader::class)->watermark($driver)->toArray();

        // Within the safety margin, the order made just before the read counts as written.
        expect(array_map(static fn (AffectedRecord $r): int => $r->id, app(NewWritesDetector::class)->detect($driver, $stored)->orders))->toBe([$old]);

        config(['wpup.backups.watermark.margin_seconds' => 0]);
        $detector = app(NewWritesDetector::class);

        expect($stored['tables'][$hpos ? 'wc_orders' : 'posts']['max_id'])->toBeGreaterThanOrEqual($old)
            ->and($stored['tables']['wc_orders']['present'])->toBeTrue()
            ->and($stored['tables']['users']['fingerprint'])->toMatch('/^\d+:\d+$/')
            ->and($detector->detect($driver, $stored)->status)->toBe(NewWritesStatus::None);

        shopEval($driver, "wc_get_order({$old})->update_status('processing'); echo 'ok';");
        $orders[] = $new = shopOrder($driver, 'completed');
        $email = 'wpup-it-'.bin2hex(random_bytes(4)).'@example.test';
        $created = $driver->wp(['user', 'create', 'wpup-it-'.bin2hex(random_bytes(4)), $email, '--role=customer', '--porcelain', '--skip-plugins', '--skip-themes']);
        $users[] = $user = (int) trim($created->stdout);
        $writes = $detector->detect($driver, $stored);

        expect($writes->status)->toBe(NewWritesStatus::Found)
            ->and(array_map(static fn (AffectedRecord $r): array => [$r->id, $r->new, $r->label], $writes->orders))->toBe([[$new, true, 'comanda, completed'], [$old, false, 'comanda, processing']])
            ->and(array_map(static fn (AffectedRecord $r): array => [$r->id, $r->new, $r->label], $writes->users))->toBe([[$user, true, 'customer']])
            ->and($writes->tables)->toHaveKey($hpos ? 'wc_orders' : 'posts')
            ->and($writes->describe())->not->toContain($email);
    } finally {
        foreach ($orders as $id) {
            shopEval($driver, "\$o = wc_get_order({$id}); if (\$o) { \$o->delete(true); } echo 'ok';");
        }
        foreach ($users as $id) {
            $driver->wp(['user', 'delete', (string) $id, '--yes', '--skip-plugins', '--skip-themes']);
        }
        shopEval($driver, "update_option('woocommerce_custom_orders_table_enabled', ".var_export($original, true)."); echo 'ok';");
    }
})->with([
    'HPOS, PHP 8.3' => ['wp83', true],
    'posts storage, PHP 8.3' => ['wp83', false],
    'HPOS, PHP 7.4' => ['wp74', true],
    'HPOS, MySQL 8.0' => ['wp82', true],
]);

it('detects a user updated in place, which no ID nor date shows', function (): void {
    $driver = app(SshDriverFactory::class)->forSite(Fixtures::site('wp83'), AuditActor::system('tests'));
    $login = 'wpup-it-'.bin2hex(random_bytes(4));
    $user = (int) trim($driver->wp(['user', 'create', $login, "{$login}@example.test", '--role=customer', '--porcelain', '--skip-plugins', '--skip-themes'])->stdout);

    try {
        config(['wpup.backups.watermark.margin_seconds' => 0]);
        sleep(1);
        $stored = app(ShopWritesReader::class)->watermark($driver)->toArray();
        $driver->wp(['user', 'update', (string) $user, "--user_email={$login}-nou@example.test", '--skip-plugins', '--skip-themes']);

        $writes = app(NewWritesDetector::class)->detect($driver, $stored);

        expect($writes->status)->toBe(NewWritesStatus::Found)
            ->and($writes->unexplained)->toBe(['users'])
            ->and($writes->describe())->not->toContain('@example.test');
    } finally {
        $driver->wp(['user', 'delete', (string) $user, '--yes', '--skip-plugins', '--skip-themes']);
    }
});
