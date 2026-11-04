<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Database part of a restore point (ADR-0009, UZEROWPUP-38): lists the site's
 * tables, dumps them (consistent, compressed, on the site's server, outside the
 * docroot), and checks that the dump contains every one of them. A dump with
 * missing tables is deleted and the backup fails: there is no restore point.
 */
final class DatabaseBackup
{
    /**
     * @param  string  $label  identifies the run in the file name (run id or timestamp)
     *
     * @throws BackupFailed
     * @throws DriverException
     */
    public function run(SiteDriver $driver, string $label): DatabaseBackupResult
    {
        $name = $this->fileName($label);

        $expected = $driver->databaseTables();
        if ($expected === []) {
            throw BackupFailed::noTables();
        }

        $file = $driver->dumpDatabase($name);

        try {
            $missing = array_values(array_diff($expected, $driver->dumpedTables($file)));
            if ($missing !== []) {
                throw BackupFailed::missingTables($missing);
            }
        } catch (Throwable $e) {
            $this->discard($driver, $file->path);

            throw $e;
        }

        return new DatabaseBackupResult($file, $expected);
    }

    private function fileName(string $label): string
    {
        $name = sprintf(config()->string('wpup.backups.database.file_name'), $label, bin2hex(random_bytes(4)));

        return trim($label) !== ''
            && preg_match(config()->string('wpup.backups.remote_name_pattern'), $name) === 1
            && ! str_contains($name, '..')
            ? $name
            : throw BackupFailed::invalidFileName($name);
    }

    /**
     * Best effort: the original failure is what the operator must see.
     */
    private function discard(SiteDriver $driver, string $path): void
    {
        try {
            $driver->remove($path);
        } catch (DriverException $e) {
            // The dump stays in the work directory (0600); the cleanup job removes it.
            Log::warning('Could not remove a rejected database dump.', ['path' => $path, 'reason' => $e->getMessage()]);
        }
    }
}
