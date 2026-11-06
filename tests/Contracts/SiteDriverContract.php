<?php

declare(strict_types=1);

namespace Tests\Contracts;

use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Data\UpdatePackage;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\Exceptions\IntegrityCheckFailed;
use App\Domain\Drivers\Exceptions\WorkFileNotFound;
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

            it('reports the PHP it runs WP-CLI with, consistent with the inventory', function () use ($factory): void {
                $driver = $factory();
                $runtime = $driver->phpRuntime();

                expect($runtime->version)->toMatch('/^\d+\.\d+\.\d+/')
                    ->and($runtime->binary)->not->toBe('')
                    ->and($driver->installedComponents()->phpVersion)->toBe($runtime->version);
            });

            it('adopts only an installed PHP version for WP-CLI', function () use ($factory): void {
                $driver = $factory();
                $current = $driver->phpRuntime();

                expect($driver->adoptWebPhp('0.1'))->toBeNull()
                    ->and($driver->phpRuntime()->binary)->toBe($current->binary);
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

            it('lists the site tables and finds all of them in its dump', function () use ($factory): void {
                $driver = $factory();
                $dump = $driver->dumpDatabase(SiteDriverContract::remoteName('db.sql.gz'));

                try {
                    $tables = $driver->databaseTables();

                    expect($tables)->not->toBeEmpty()
                        ->and(array_diff($tables, $driver->dumpedTables($dump)))->toBe([]);
                } finally {
                    $driver->remove($dump->path);
                }
            });

            it('reports the physical archive root and keeps its work directory out of it', function () use ($factory): void {
                $driver = $factory();
                $exclude = $driver->workDirExclude();

                expect(str_starts_with($driver->archiveRoot(), '/') && ! str_ends_with($driver->archiveRoot(), '/'))->toBeTrue();
                expect($exclude === null || ($exclude !== '' && ! str_starts_with($exclude, '/') && ! str_contains($exclude, '..')))->toBeTrue();
            });

            it('refuses to read the tables of a missing dump', function () use ($factory): void {
                expect(fn () => $factory()->dumpedTables(new RemoteFile(SiteDriverContract::remoteName('none.sql.gz'), 1, str_repeat('0', 64))))
                    ->toThrow(DriverException::class);
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

            it('restores the database from a nested work path, and refuses other bytes without changing anything', function () use ($factory): void {
                $driver = $factory();
                $original = trim($driver->wp(['option', 'get', 'blogname'])->stdout);
                $dir = SiteDriverContract::remoteName('store');
                $dump = $driver->dumpDatabase(SiteDriverContract::remoteName('db.sql.gz'));
                $stored = new RemoteFile("{$dir}/site-1/r1/db.sql.gz", $dump->sizeBytes, $dump->sha256);
                $driver->moveWorkFile($dump->path, $stored->path);

                try {
                    $driver->wp(['option', 'update', 'blogname', 'changed by the contract']);
                    expect(fn () => $driver->restoreDatabase(new RemoteFile($stored->path, $stored->sizeBytes, str_repeat('0', 64))))->toThrow(DriverException::class)
                        ->and(trim($driver->wp(['option', 'get', 'blogname'])->stdout))->toBe('changed by the contract');

                    $driver->restoreDatabase($stored);

                    expect(trim($driver->wp(['option', 'get', 'blogname'])->stdout))->toBe($original)
                        ->and($driver->listWorkFiles($dir))->toHaveCount(1)
                        ->and(SiteDriverContract::restoreTemporaries($driver))->toBe([]);
                } finally {
                    $driver->wp(['option', 'update', 'blogname', $original]);
                    $driver->removeWorkFile($stored->path);
                }
            });

            it('restores the files from a nested work path, protecting what the archive left out', function () use ($factory): void {
                $driver = $factory();
                $dir = SiteDriverContract::remoteName('store');
                $archive = $driver->archiveFiles(SiteDriverContract::remoteName('files.tar.gz'), ['wp-content/uploads']);
                $stored = new RemoteFile("{$dir}/site-1/r1/files.tar.gz", $archive->sizeBytes, $archive->sha256);
                $driver->moveWorkFile($archive->path, $stored->path);
                $zip = SiteDriverContract::contractPluginZip('1.2.0');

                try {
                    expect($driver->archiveExcludes($stored))->toContain('wp-content/uploads');
                    $driver->applyUpdate(SiteDriverContract::package($zip, '1.2.0'));
                    expect(fn () => $driver->restoreFiles(new RemoteFile($stored->path, $stored->sizeBytes, str_repeat('0', 64))))->toThrow(DriverException::class)
                        ->and($driver->installedComponents()->find(ComponentType::Plugin, 'wpup-contract-plugin'))->not->toBeNull();

                    $driver->restoreFiles($stored);

                    expect($driver->installedComponents()->find(ComponentType::Plugin, 'wpup-contract-plugin'))->toBeNull()
                        ->and(SiteDriverContract::restoreTemporaries($driver))->toBe([]);
                } finally {
                    @unlink($zip);
                    $driver->removeWorkFile($stored->path);
                }
            });

            it('installs a package of the tool server idempotently, keeping its activation and no upload', function () use ($factory): void {
                $driver = $factory();
                $archive = $driver->archiveFiles(SiteDriverContract::remoteName('files.tar.gz'), ['wp-content/uploads']);
                $zips = [SiteDriverContract::contractPluginZip('1.1.0'), SiteDriverContract::contractPluginZip('1.2.0'), SiteDriverContract::contractPluginZip('1.3.0')];
                $installed = static fn (): ?ComponentVersion => $driver->installedComponents()->find(ComponentType::Plugin, 'wpup-contract-plugin');

                try {
                    $first = $driver->applyUpdate(SiteDriverContract::package($zips[0], '1.1.0'));
                    expect($first->result->successful())->toBeTrue()
                        ->and($first->alreadyInstalled)->toBeFalse()
                        ->and($first->installed->version)->toBe('1.1.0')
                        ->and($installed()?->version)->toBe('1.1.0')
                        ->and($installed()?->active)->toBeFalse()
                        ->and($driver->wp(['plugin', 'activate', 'wpup-contract-plugin'])->successful())->toBeTrue();

                    $update = SiteDriverContract::package($zips[1], '1.2.0');
                    expect($driver->applyUpdate($update)->installed->active)->toBeTrue()
                        ->and($driver->applyUpdate($update)->alreadyInstalled)->toBeTrue()
                        ->and($installed()?->version)->toBe('1.2.0')
                        ->and($installed()?->active)->toBeTrue()
                        ->and($driver->installedState(ComponentType::Plugin, 'wpup-contract-plugin'))->toEqual($installed());

                    // Other bytes than the artifact's: nothing is uploaded nor changed.
                    $tampered = new UpdatePackage(ComponentType::Plugin, 'wpup-contract-plugin', '1.3.0', $zips[2], str_repeat('0', 64), (int) filesize($zips[2]));
                    expect(fn () => $driver->applyUpdate($tampered))->toThrow(IntegrityCheckFailed::class)
                        ->and($installed()?->version)->toBe('1.2.0')
                        ->and(fn () => $driver->applyUpdate(new UpdatePackage(ComponentType::Translation, 'ca', '1.0', $zips[2], (string) hash_file('sha256', $zips[2]), (int) filesize($zips[2]))))
                        ->toThrow(DriverException::class)
                        ->and(SiteDriverContract::packageTemporaries($driver))->toBe([]);

                    // A package that holds another version than the target fails, and leaves no upload either.
                    expect(fn () => $driver->applyUpdate(SiteDriverContract::package($zips[2], '1.4.0')))->toThrow(DriverException::class)
                        ->and(SiteDriverContract::packageTemporaries($driver))->toBe([]);

                    $driver->wp(['plugin', 'deactivate', 'wpup-contract-plugin']);
                    $driver->restoreFiles($archive);

                    expect($installed())->toBeNull();
                } finally {
                    array_map(unlink(...), $zips);
                    $driver->remove($archive->path);
                }
            });

            it('reads one component as the inventory reports it', function () use ($factory): void {
                $driver = $factory();
                $all = $driver->installedComponents();
                $plugin = array_values(array_filter($all->components, static fn (ComponentVersion $c): bool => $c->type === ComponentType::Plugin))[0];

                expect($driver->installedState(ComponentType::Core, 'wordpress')?->version)->toBe($all->coreVersion)
                    ->and($driver->installedState(ComponentType::Plugin, $plugin->slug))->toEqual($plugin)
                    ->and($driver->installedState(ComponentType::Plugin, 'wpup-contract-not-installed'))->toBeNull()
                    ->and(fn () => $driver->installedState(ComponentType::Translation, 'ca'))->toThrow(DriverException::class);
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

            it('writes a temporary web file without overwriting, and removes it idempotently', function () use ($factory): void {
                $driver = $factory();
                $name = 'wpup-contract-'.bin2hex(random_bytes(4)).'.php';

                try {
                    $driver->putWebFile($name, '<?php echo 1;');
                    expect(fn () => $driver->putWebFile($name, '<?php echo 2;'))->toThrow(DriverException::class);
                } finally {
                    $driver->removeWebFile($name);
                }
                $driver->removeWebFile($name);
                expect(fn () => $driver->putWebFile('../escape.php', '<?php'))->toThrow(DriverException::class)
                    ->and(fn () => $driver->putWebFile('wp-config.txt', 'x'))->toThrow(DriverException::class);
            });

            it('reports free disk space', function () use ($factory): void {
                expect($factory()->freeDiskBytes())->toBeGreaterThan(0);
            });

            it('reports the disk the site uses and that its code is writable', function () use ($factory): void {
                $driver = $factory();

                expect($driver->diskUsageBytes())->toBeGreaterThan(0)
                    ->and($driver->unwritablePaths())->toBe([]);
            });

            it('estimates the database size from the site tables', function () use ($factory): void {
                $result = $factory()->wp(['db', 'size', '--size_format=b', '--all-tables-with-prefix', '--format=json']);
                $rows = json_decode(trim($result->stdout), true);
                $first = is_array($rows) && is_array($rows[0] ?? null) ? $rows[0] : [];

                expect($result->successful())->toBeTrue()
                    ->and($first)->toHaveKeys(['Name', 'Size'])
                    ->and((int) (is_string($first['Size'] ?? null) ? $first['Size'] : 0))->toBeGreaterThan(0);
            });

            it('moves a file into a nested work path without ever overwriting', function () use ($factory): void {
                $driver = $factory();
                $dir = SiteDriverContract::remoteName('store');
                $local = SiteDriverContract::localTemp();
                file_put_contents($local, 'restore point bytes');

                try {
                    $first = $driver->upload($local, SiteDriverContract::remoteName('a.gz'));
                    $second = $driver->upload($local, SiteDriverContract::remoteName('b.gz'));
                    $moved = $driver->moveWorkFile($first->path, "{$dir}/site-1/r1/db.sql.gz");

                    expect($moved->path)->toBe("{$dir}/site-1/r1/db.sql.gz")
                        ->and($moved->sizeBytes)->toBe(19)
                        ->and($driver->statWorkFile($first->path))->toBeNull()
                        ->and(fn () => $driver->moveWorkFile($second->path, "{$dir}/site-1/r1/db.sql.gz"))->toThrow(DriverException::class)
                        ->and(fn () => $driver->moveWorkFile($second->path, "{$dir}/site-1/r1"))->toThrow(DriverException::class)
                        ->and($driver->statWorkFile("{$dir}/site-1/r1/db.sql.gz", true)?->sha256)->toBe(hash('sha256', 'restore point bytes'))
                        ->and($driver->statWorkFile($second->path))->not->toBeNull()
                        ->and(fn () => $driver->moveWorkFile(SiteDriverContract::remoteName('none.gz'), "{$dir}/x.gz"))->toThrow(DriverException::class);
                } finally {
                    @unlink($local);
                    $driver->removeWorkFile("{$dir}/site-1/r1/db.sql.gz");
                    if (isset($second)) {
                        $driver->remove($second->path);
                    }
                }
            });

            it('stats, lists, opens and removes work files', function () use ($factory): void {
                $driver = $factory();
                $dir = SiteDriverContract::remoteName('store');
                $local = SiteDriverContract::localTemp();
                file_put_contents($local, 'abc');

                try {
                    $driver->moveWorkFile($driver->upload($local, SiteDriverContract::remoteName('x.gz'))->path, "{$dir}/b/two.gz");
                    $driver->moveWorkFile($driver->upload($local, SiteDriverContract::remoteName('y.gz'))->path, "{$dir}/a/one.gz");
                    $stat = $driver->statWorkFile("{$dir}/a/one.gz");
                    $paths = array_map(static fn ($f): string => $f->path, $driver->listWorkFiles($dir));
                    sort($paths);
                    $stream = $driver->openWorkFile("{$dir}/a/one.gz");
                    $contents = stream_get_contents($stream);
                    fclose($stream);

                    expect($stat?->sizeBytes)->toBe(3)
                        ->and($stat?->sha256)->toBeNull()
                        ->and($stat?->modifiedAt === null || $stat->modifiedAt > 0)->toBeTrue()
                        ->and($paths)->toBe(["{$dir}/a/one.gz", "{$dir}/b/two.gz"])
                        ->and(array_map(static fn ($f): string => $f->path, $driver->listWorkFiles("{$dir}/a")))->toBe(["{$dir}/a/one.gz"])
                        ->and($driver->listWorkFiles("{$dir}/missing"))->toBe([])
                        ->and($contents)->toBe('abc')
                        ->and(in_array("{$dir}/b/two.gz", array_map(static fn ($f): string => $f->path, $driver->listWorkFiles('')), true))->toBeTrue()
                        ->and(fn () => $driver->openWorkFile("{$dir}/missing.gz"))->toThrow(WorkFileNotFound::class);

                    $driver->removeWorkFile("{$dir}/a/one.gz");
                    $driver->removeWorkFile("{$dir}/a/one.gz");
                    expect($driver->statWorkFile("{$dir}/a/one.gz"))->toBeNull();
                } finally {
                    @unlink($local);
                    $driver->removeWorkFile("{$dir}/a/one.gz");
                    $driver->removeWorkFile("{$dir}/b/two.gz");
                }
            });

            it('reports the physical home and work directory, the latter inside the former', function () use ($factory): void {
                $area = $factory()->workArea();

                expect(str_starts_with($area->home, '/'))->toBeTrue()
                    ->and(str_starts_with($area->workDir, rtrim($area->home, '/').'/'))->toBeTrue();
            });

            it('refuses work paths outside its area', function (string $path) use ($factory): void {
                $driver = $factory();

                if ($path !== '') {
                    // '' is the whole work area for a listing, and nothing else.
                    expect(fn () => $driver->listWorkFiles($path))->toThrow(DriverException::class);
                }
                expect(fn () => $driver->statWorkFile($path))->toThrow(DriverException::class)
                    ->and(fn () => $driver->openWorkFile($path))->toThrow(DriverException::class)
                    ->and(fn () => $driver->removeWorkFile($path))->toThrow(DriverException::class)
                    ->and(fn () => $driver->moveWorkFile('x.gz', $path))->toThrow(DriverException::class);
            })->with(['../escape.gz', '/etc/passwd', 'a/../../b', 'a//b', '', '.hidden', 'a/.b', "a\0b", 'a/b/', "a/b.gz\n", "a'b", 'a b']);

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

    /**
     * Temporary files of a restore left anywhere in the work directory.
     *
     * @return list<string>
     */
    public static function restoreTemporaries(SiteDriver $driver): array
    {
        return array_values(array_filter(
            array_map(static fn ($f): string => $f->path, $driver->listWorkFiles('')),
            static fn (string $path): bool => preg_match(SiteDriver::RESTORE_TEMP_PATTERN, basename($path)) === 1 || str_contains($path, '.restore.'),
        ));
    }

    /**
     * Packages applyUpdate() uploaded and left in the work directory.
     *
     * @return list<string>
     */
    public static function packageTemporaries(SiteDriver $driver): array
    {
        return array_values(array_filter(
            array_map(static fn ($f): string => $f->path, $driver->listWorkFiles('')),
            static fn (string $path): bool => preg_match(SiteDriver::PACKAGE_TEMP_PATTERN, basename($path)) === 1,
        ));
    }

    /**
     * The contract plugin's package as the tool server holds it.
     */
    public static function package(string $zip, string $version): UpdatePackage
    {
        return new UpdatePackage(ComponentType::Plugin, 'wpup-contract-plugin', $version, $zip, (string) hash_file('sha256', $zip), (int) filesize($zip));
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
