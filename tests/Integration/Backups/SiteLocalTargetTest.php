<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\DatabaseBackup;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Drivers\DriverFactory;
use App\Domain\Secrets\SecretRedactor;
use Illuminate\Support\Facades\Http;
use Tests\Contracts\BackupTargetContract;
use Tests\Support\Fixtures;

/*
 * The destination on the site's own server (UZEROWPUP-40) against the Docker
 * test sites (make fixtures-up). Never real sites. Each contract test gets its
 * own store root, removed at the end.
 */

const SITE_LOCAL_TEST_ROOT = 'restore-points/it-';

/**
 * @return array{SiteLocalTarget, string} the target and its root
 */
function siteLocalFixture(string $name): array
{
    $root = SITE_LOCAL_TEST_ROOT.bin2hex(random_bytes(6));
    $driver = app(DriverFactory::class)->forSite(Fixtures::site($name), AuditActor::system('tests'));

    return [new SiteLocalTarget($driver, app(SecretRedactor::class), $root, 1_048_576), $root];
}

foreach (['wp83', 'wp74'] as $fixture) {
    if (Fixtures::up($fixture)) {
        BackupTargetContract::register(fn (): BackupTarget => siteLocalFixture($fixture)[0], "SiteLocalTarget ({$fixture})");
    } else {
        it("passes the BackupTarget contract on {$fixture}")->skip('Llocs de prova aturats: make fixtures-up');
    }
}

afterAll(function (): void {
    foreach (['wp83', 'wp74'] as $fixture) {
        if (Fixtures::up($fixture)) {
            Fixtures::run($fixture, 'rm -rf "$HOME/.wpup/"'.escapeshellarg(SITE_LOCAL_TEST_ROOT).'*');
        }
    }
});

if (! Fixtures::up('wp83')) {
    it('keeps restore points on the test site')->skip('Llocs de prova aturats: make fixtures-up');

    return;
}

it('keeps objects outside the docroot, where the web cannot serve them', function (): void {
    [$target, $root] = siteLocalFixture('wp83');
    $target->put('site-1/r1/db.sql.gz', BackupTargetContract::stream('wpup-secret-rows'));

    // Physical paths on the site: the store is not under the docroot nor the WordPress root.
    $store = trim(Fixtures::run('wp83', 'cd "$HOME/.wpup/"'.escapeshellarg($root).' && pwd -P'));
    $docroot = trim(Fixtures::run('wp83', 'cd /var/www/vhosts/wp83.fixtures.test/httpdocs && pwd -P'));

    expect($store)->toStartWith('/')
        ->and(str_starts_with($store.'/', $docroot.'/'))->toBeFalse();

    foreach ([
        "/.wpup/{$root}/site-1/r1/db.sql.gz",
        "/../.wpup/{$root}/site-1/r1/db.sql.gz",
        "/%2e%2e/.wpup/{$root}/site-1/r1/db.sql.gz",
        "/{$root}/site-1/r1/db.sql.gz",
        '/site-1/r1/db.sql.gz',
        '/~sub_wp83/.wpup/'.$root.'/site-1/r1/db.sql.gz',
    ] as $path) {
        $response = Http::withoutRedirecting()->timeout(10)->get('http://wp83:8083'.$path);

        expect($response->body())->not->toContain('wpup-secret-rows')
            ->and(in_array($response->status(), [400, 403, 404], true))->toBeTrue("{$path}: HTTP {$response->status()}");
    }
});

it('keeps directories 0700 and objects 0600', function (): void {
    [$target, $root] = siteLocalFixture('wp83');
    $target->put('site-1/r1/db.sql.gz', BackupTargetContract::stream('rows'));
    $base = '"$HOME/.wpup/"'.escapeshellarg($root);

    $modes = Fixtures::run('wp83', "stat -c '%a' \"\$HOME/.wpup\" \"\$HOME/.wpup/restore-points\" {$base} {$base}/site-1 {$base}/site-1/r1 {$base}/site-1/r1/db.sql.gz");

    expect($modes)->toBe("700\n700\n700\n700\n700\n600\n")
        ->and(Fixtures::run('wp83', 'ls "$HOME/.wpup" | grep -c "^store-" || true'))->toBe("0\n");
});

it('adopts a database dump of the work directory without transferring it', function (): void {
    $site = Fixtures::site('wp83');
    $driver = app(DriverFactory::class)->forSite($site, AuditActor::system('tests'));
    $root = SITE_LOCAL_TEST_ROOT.bin2hex(random_bytes(6));
    $target = new SiteLocalTarget($driver, app(SecretRedactor::class), $root, 1_048_576);
    $dump = app(DatabaseBackup::class)->run($driver, 'it'.bin2hex(random_bytes(3)))->file;

    $stored = $target->adopt($dump, "site-{$site->id}/run-1/db.sql.gz");
    $mode = Fixtures::run('wp83', 'stat -c %a "$HOME/.wpup/"'.escapeshellarg("{$root}/site-{$site->id}/run-1/db.sql.gz"));

    expect($stored->sizeBytes)->toBe($dump->sizeBytes)
        ->and($target->checksum("site-{$site->id}/run-1/db.sql.gz"))->toBe($dump->sha256)
        ->and($driver->statWorkFile($dump->path))->toBeNull()
        ->and($mode)->toBe("600\n");
});

it('is resolved by the registry for a site linked to the shared destination', function (): void {
    $site = Fixtures::site('wp83');
    $destination = app(SiteLocalDestination::class);
    $destination->attach($site);

    $target = app(BackupTargetRegistry::class)->for($destination->record(), $site, AuditActor::system('tests'));

    expect($target)->toBeInstanceOf(SiteLocalTarget::class)
        ->and($target->exists('site-0/none.gz'))->toBeFalse();
});
