<?php

declare(strict_types=1);

namespace Tests\Contracts;

use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Data\UpdatePackage;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Enums\CheckResult;
use App\Enums\ComponentType;
use Closure;
use ZipArchive;

/**
 * Behaviour every SiteDriver must have (AGENTS.md: all implementations pass the
 * same contract suite). Usage from a test file:
 *
 *     SiteDriverContract::register(fn (): SiteDriver => new SomeDriver(...), 'some driver');
 *
 * The site behind the driver must be a seeded WordPress (fixture or fake) with
 * WP-CLI available and PHP errors logged to the log the driver reads.
 */
final class SiteDriverContract
{
    /**
     * @param  Closure(): SiteDriver  $factory
     */
    public static function register(Closure $factory, string $label): void
    {
        describe("SiteDriver contract: {$label}", function () use ($factory): void {
            it('reports its prerequisites without errors', function () use ($factory): void {
                $probe = $factory()->probe();

                expect($probe->checks)->not->toBeEmpty()
                    ->and($probe->worst())->not->toBe(CheckResult::Error);
            });

            it('lists core and at least one plugin', function () use ($factory): void {
                $installed = $factory()->installedComponents();

                expect($installed->coreVersion)->toMatch('/^\d+\.\d+(\.\d+)?/')
                    ->and($installed->phpVersion)->toMatch('/^\d+\.\d+/');

                $plugins = array_filter(
                    $installed->components,
                    static fn ($c): bool => in_array($c->type, [ComponentType::Plugin, ComponentType::Woocommerce], true),
                );
                expect($plugins)->not->toBeEmpty();
            });

            it('runs WP-CLI and agrees with the inventory', function () use ($factory): void {
                $driver = $factory();
                $result = $driver->wp(['core', 'version']);

                expect($result->successful())->toBeTrue()
                    ->and(trim($result->stdout))->toBe($driver->installedComponents()->coreVersion);
            });

            it('reports failing commands through the exit code', function () use ($factory): void {
                expect($factory()->wp(['option', 'get', 'wpup_contract_option_that_does_not_exist'])->successful())->toBeFalse();
            });

            it('dumps the database and downloads it with a verified checksum', function () use ($factory): void {
                $driver = $factory();
                $dump = $driver->dumpDatabase(SiteDriverContract::remoteName('db.sql.gz'));
                $local = SiteDriverContract::localTemp();

                try {
                    $driver->download($dump, $local);

                    expect($dump->sizeBytes)->toBeGreaterThan(0)
                        ->and(hash_file('sha256', $local))->toBe($dump->sha256);
                } finally {
                    @unlink($local);
                    $driver->remove($dump->path);
                }
            });

            it('refuses a download whose checksum does not match', function () use ($factory): void {
                $driver = $factory();
                $dump = $driver->dumpDatabase(SiteDriverContract::remoteName('db.sql.gz'));
                $local = SiteDriverContract::localTemp();

                try {
                    expect(fn () => $driver->download(new RemoteFile($dump->path, $dump->sizeBytes, str_repeat('0', 64)), $local))
                        ->toThrow(DriverException::class);
                } finally {
                    @unlink($local);
                    $driver->remove($dump->path);
                }
            });

            it('restores the database to the dumped state', function () use ($factory): void {
                $driver = $factory();
                $original = trim($driver->wp(['option', 'get', 'blogname'])->stdout);
                $dump = $driver->dumpDatabase(SiteDriverContract::remoteName('db.sql.gz'));

                try {
                    $driver->wp(['option', 'update', 'blogname', 'changed by the contract']);
                    $driver->restoreDatabase($dump);

                    expect(trim($driver->wp(['option', 'get', 'blogname'])->stdout))->toBe($original);
                } finally {
                    $driver->remove($dump->path);
                }
            });

            it('installs an uploaded package idempotently and restores the files', function () use ($factory): void {
                $driver = $factory();
                $archive = $driver->archiveFiles(SiteDriverContract::remoteName('files.tar.gz'), ['wp-content/uploads']);
                $zip = SiteDriverContract::contractPluginZip('1.1.0');

                try {
                    $remote = $driver->upload($zip, SiteDriverContract::remoteName('wpup-contract-plugin.zip'));
                    $package = new UpdatePackage(ComponentType::Plugin, 'wpup-contract-plugin', '1.1.0', $remote);

                    expect($driver->applyUpdate($package)->successful())->toBeTrue()
                        ->and($driver->applyUpdate($package)->successful())->toBeTrue()
                        ->and($driver->installedComponents()->find(ComponentType::Plugin, 'wpup-contract-plugin')?->version)->toBe('1.1.0');

                    $driver->restoreFiles($archive);

                    expect($driver->installedComponents()->find(ComponentType::Plugin, 'wpup-contract-plugin'))->toBeNull();
                    $driver->remove($remote->path);
                } finally {
                    @unlink($zip);
                    $driver->remove($archive->path);
                }
            });

            it('toggles maintenance mode', function () use ($factory): void {
                $driver = $factory();

                try {
                    $driver->setMaintenanceMode(true);
                    $driver->setMaintenanceMode(true);
                    expect($driver->wp(['maintenance-mode', 'is-active'])->successful())->toBeTrue();
                } finally {
                    $driver->setMaintenanceMode(false);
                }

                expect($driver->wp(['maintenance-mode', 'is-active'])->successful())->toBeFalse();
            });

            it('returns only new PHP log lines after a cursor', function () use ($factory): void {
                $driver = $factory();
                $driver->wp(['eval', 'error_log("wpup-contract-before");']);
                $cursor = $driver->phpLogCursor();
                $marker = 'wpup-contract-'.bin2hex(random_bytes(4));
                $driver->wp(['eval', 'error_log("'.$marker.'");']);

                $lines = implode("\n", $driver->phpLogSince($cursor));

                expect($lines)->toContain($marker)
                    ->and($lines)->not->toContain('wpup-contract-before');
            });

            it('reports free disk space', function () use ($factory): void {
                expect($factory()->freeDiskBytes())->toBeGreaterThan(0);
            });

            it('fails to download a removed file', function () use ($factory): void {
                $driver = $factory();
                $dump = $driver->dumpDatabase(SiteDriverContract::remoteName('db.sql.gz'));
                $driver->remove($dump->path);
                $local = SiteDriverContract::localTemp();

                try {
                    expect(fn () => $driver->download($dump, $local))->toThrow(DriverException::class);
                } finally {
                    @unlink($local);
                }
            });
        });
    }

    public static function remoteName(string $suffix): string
    {
        return 'wpup-contract-'.bin2hex(random_bytes(4)).'-'.$suffix;
    }

    public static function localTemp(): string
    {
        return (string) tempnam(sys_get_temp_dir(), 'wpup-contract-');
    }

    /**
     * Builds a minimal, harmless plugin zip at the given version.
     */
    public static function contractPluginZip(string $version): string
    {
        $path = sys_get_temp_dir().'/wpup-contract-plugin-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('wpup-contract-plugin/wpup-contract-plugin.php', <<<PHP
            <?php
            /**
             * Plugin Name: Uzero wpup contract plugin
             * Version: {$version}
             * Description: Test-only plugin installed by the SiteDriver contract suite.
             */
            PHP);
        $zip->close();

        return $path;
    }
}
