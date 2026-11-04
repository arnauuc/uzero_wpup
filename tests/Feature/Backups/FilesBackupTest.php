<?php

declare(strict_types=1);

use App\Domain\Backups\BackupFailed;
use App\Domain\Backups\FilesBackup;
use App\Domain\Drivers\Exceptions\DriverException;
use Tests\Support\FakeDriver;

/*
 * Code part of the restore point (UZEROWPUP-39) against the FakeDriver.
 */

it('leaves uploads, caches and other backup plugins out by default', function (): void {
    $driver = new FakeDriver;

    $result = app(FilesBackup::class)->run($driver, false, '42');

    expect($result->file->path)->toMatch('/^files-42-[0-9a-f]{8}\.tar\.gz$/')
        ->and($result->excludes)->toContain('wp-content/uploads', 'wp-content/cache', 'wp-content/updraft', 'wp-content/ai1wm-backups', 'wp-content/backups-dup-lite', 'wp-content/backups-dup-pro')
        ->and($result->excludes)->not->toContain('wp-content/uploads/backwpup-*')
        ->and($result->warnings)->toBe([])
        ->and($driver->files)->toHaveKey($result->file->path);
});

it('includes uploads when the profile says so, but still leaves the backups of other plugins out', function (): void {
    $result = app(FilesBackup::class)->run(new FakeDriver, true, '42');

    expect($result->excludes)->not->toContain('wp-content/uploads')
        ->and($result->excludes)->toContain('wp-content/cache', 'wp-content/uploads/backwpup-*', 'wp-content/uploads/snapshots');
});

it('never excludes the code or wp-config.php', function (bool $uploads): void {
    $excludes = app(FilesBackup::class)->excludes($uploads);

    foreach (['wp-config.php', 'wp-content/plugins', 'wp-content/themes', 'wp-content/mu-plugins', 'wp-content/object-cache.php', 'wp-includes', 'wp-admin'] as $code) {
        expect($excludes)->not->toContain($code);
    }
})->with([false, true]);

it('reads the exclude list from the configuration', function (): void {
    config(['wpup.backups.files.exclude' => ['wp-content/custom-cache']]);

    expect(app(FilesBackup::class)->excludes(false))->toBe(['wp-content/custom-cache', 'wp-content/uploads']);
});

it('warns when wp-config.php lives above the WordPress root', function (): void {
    $driver = new FakeDriver;
    $driver->configPath = '/var/www/wp-config.php';

    $result = app(FilesBackup::class)->run($driver, false, '42');

    expect($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0])->toContain('wp-config.php és fora de l\'arrel')->toContain('/var/www/wp-config.php');
});

it('warns instead of failing when the location of wp-config.php cannot be checked', function (): void {
    $driver = new FakeDriver;
    $driver->failingCommands = ['config path'];

    expect(app(FilesBackup::class)->run($driver, false, '42')->warnings)->toHaveCount(1);

    $driver->wpFailure = new DriverException('connexió perduda');

    expect(app(FilesBackup::class)->run($driver, false, '43')->warnings)->toHaveCount(1);
});

it('refuses an invalid label without archiving anything', function (string $label): void {
    $driver = new FakeDriver;

    expect(fn () => app(FilesBackup::class)->run($driver, false, $label))->toThrow(BackupFailed::class, 'no és vàlid')
        ->and($driver->writeCalls)->toBe([]);
})->with(['../x', 'a b', '']);
