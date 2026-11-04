<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;

/**
 * Code part of a restore point (ADR-0009, UZEROWPUP-39): core, plugins, themes,
 * mu-plugins, drop-ins and wp-config.php, in a tar.gz on the site's server
 * (outside the docroot, 0600). Uploads are left out unless the site profile
 * includes them; caches and the archives of other backup plugins always are.
 */
final class FilesBackup
{
    /**
     * @param  string  $label  identifies the run in the file name (run id or timestamp)
     *
     * @throws BackupFailed
     * @throws DriverException
     */
    public function run(SiteDriver $driver, bool $includeUploads, string $label): FilesBackupResult
    {
        $name = BackupFileName::make(config()->string('wpup.backups.files.file_name'), $label);
        $excludes = $this->excludes($includeUploads);

        $file = $driver->archiveFiles($name, $excludes);

        return new FilesBackupResult($file, $excludes, $this->warnings($driver));
    }

    /**
     * @return list<string>
     */
    public function excludes(bool $includeUploads): array
    {
        $always = $this->configList('wpup.backups.files.exclude');

        return array_values(array_unique($includeUploads
            ? [...$always, ...$this->configList('wpup.backups.files.exclude_in_uploads')]
            : [...$always, config()->string('wpup.backups.files.uploads_path')]));
    }

    /**
     * WordPress accepts wp-config.php one level above its root; the archive
     * never goes outside the root, so that file would not be in it.
     *
     * @return list<string>
     */
    private function warnings(SiteDriver $driver): array
    {
        try {
            $config = $driver->wp(['config', 'path']);
            $root = $driver->wp(['eval', 'echo rtrim(ABSPATH, "/");']);
        } catch (DriverException) {
            return ['No s\'ha pogut comprovar on és wp-config.php: verifica que l\'arxiu el conté.'];
        }

        $path = trim($config->stdout);
        $abspath = trim($root->stdout);
        if (! $config->successful() || ! $root->successful() || $path === '' || $abspath === '') {
            return ['No s\'ha pogut comprovar on és wp-config.php: verifica que l\'arxiu el conté.'];
        }

        return rtrim(dirname($path), '/') === rtrim($abspath, '/')
            ? []
            : ["wp-config.php és fora de l'arrel de WordPress ({$path}): no és dins l'arxiu i la restauració no el tornarà a posar."];
    }

    /**
     * @return list<string>
     */
    private function configList(string $key): array
    {
        $list = config($key);

        return is_array($list) ? array_values(array_filter($list, is_string(...))) : [];
    }
}
