<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Secrets\SecretRedactor;
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
    public function __construct(private readonly SecretRedactor $redactor) {}

    /**
     * @param  string  $label  identifies the run in the file name (run id or timestamp)
     *
     * @throws BackupFailed
     * @throws DriverException
     */
    public function run(SiteDriver $driver, string $label): DatabaseBackupResult
    {
        $name = BackupFileName::make(config()->string('wpup.backups.database.file_name'), $label);

        $expected = $driver->databaseTables();
        if ($expected === []) {
            throw BackupFailed::noTables();
        }

        $file = $driver->dumpDatabase($name);

        try {
            $missing = array_values(array_diff($expected, $driver->dumpedTables($file)));
        } catch (Throwable $e) {
            $this->discard($driver, $file->path);

            throw $e;
        }

        if ($missing !== []) {
            throw BackupFailed::missingTables($missing, $this->discard($driver, $file->path));
        }

        return new DatabaseBackupResult($file, $expected);
    }

    /**
     * Best effort: the original failure is what the operator must see. Returns
     * whether the dump was removed.
     */
    private function discard(SiteDriver $driver, string $path): bool
    {
        try {
            $driver->remove($path);

            return true;
        } catch (DriverException $e) {
            // The dump stays in the work directory (0600); the cleanup job removes it.
            Log::warning('Could not remove a rejected database dump.', ['path' => $path, 'reason' => $this->redactor->redact($e->getMessage())]);

            return false;
        }
    }
}
