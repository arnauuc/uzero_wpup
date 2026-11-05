<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\BackupKind;
use App\Models\Backup;
use App\Models\Site;
use App\Support\PrivateDirectory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Puts one registered copy back on its site (UZEROWPUP-46).
 *
 * - verify() checks that the destination still holds the copy's bytes
 *   (its own SHA-256, never the stored value).
 * - restore() checks it again and hands the file to the driver: an object of
 *   the site-local store is restored in place (no copy, nothing over the
 *   network); an external one is streamed to a private file of the tool
 *   server, hashed, uploaded to the site's work directory (checksum verified)
 *   and removed afterwards. The driver checks size and SHA-256 on the site
 *   once more right before it restores, so a mismatch changes nothing.
 */
final readonly class SiteRestore
{
    public function __construct(
        private BackupTargetRegistry $registry,
        private SecretRedactor $redactor,
    ) {}

    /**
     * @throws RestoreRefused when the destination cannot be read or holds other bytes
     */
    public function verify(Site $site, Backup $row, AuditActor $actor): BackupTarget
    {
        $destination = $row->backupTarget->name ?? "#{$row->backup_target_id}";

        try {
            $record = $row->backupTarget ?? throw new BackupTargetException("La destinació {$row->backup_target_id} no existeix.");
            $target = $this->registry->for($record, $site, $actor);
            $actual = $target->checksum($row->path);
        } catch (BackupTargetException $e) {
            throw new RestoreRefused("No s'ha pogut comprovar la còpia #{$row->id} a «{$destination}»: {$this->redactor->redact($e->getMessage())}");
        }
        if (! hash_equals($row->sha256, $actual)) {
            throw new RestoreRefused("La còpia #{$row->id} ({$row->path}) a «{$destination}» ja no té el SHA-256 registrat: no es restaura res.");
        }

        return $target;
    }

    /**
     * @throws RestoreRefused before touching the site
     * @throws DriverException when the restore itself fails (the site may be half restored)
     */
    public function restore(Site $site, SiteDriver $driver, Backup $row, AuditActor $actor): void
    {
        $target = $this->verify($site, $row, $actor);

        if ($target instanceof SiteLocalTarget) {
            $this->apply($driver, $row->kind, $target->workFile($row->path, $row->size_bytes, $row->sha256));

            return;
        }

        $local = $this->download($target, $row);
        $name = 'restore-'.bin2hex(random_bytes(8)).'.'.RestorePointService::objectName($row->kind);

        try {
            try {
                $file = $driver->upload($local, $name);
            } finally {
                @unlink($local);
            }
            if ($file->sha256 !== $row->sha256 || $file->sizeBytes !== $row->size_bytes) {
                throw new RestoreRefused("La còpia #{$row->id} ha arribat al lloc amb uns altres bytes: no es restaura res.");
            }
            $this->apply($driver, $row->kind, $file);
        } finally {
            try {
                $driver->remove($name);
            } catch (DriverException $e) {
                Log::warning('Could not remove the uploaded copy of a restore.', ['name' => $name, 'reason' => $this->redactor->redact($e->getMessage())]);
            }
        }
    }

    /**
     * @throws DriverException
     */
    private function apply(SiteDriver $driver, BackupKind $kind, RemoteFile $file): void
    {
        match ($kind) {
            BackupKind::Database => $driver->restoreDatabase($file),
            BackupKind::Files => $driver->restoreFiles($file),
        };
    }

    /**
     * The object, streamed in chunks to a private file of the staging
     * directory, which must hold the registered bytes.
     *
     * @throws RestoreRefused
     */
    private function download(BackupTarget $target, Backup $row): string
    {
        try {
            $path = PrivateDirectory::ensure(config()->string('wpup.backups.staging_dir')).'/restore-'.bin2hex(random_bytes(16));
            $stream = $target->readStream($row->path);
        } catch (BackupTargetException|RuntimeException $e) {
            throw new RestoreRefused("No s'ha pogut llegir la còpia #{$row->id}: {$this->redactor->redact($e->getMessage())}");
        }

        $hash = hash_init('sha256');
        $out = @fopen($path, 'xb');
        $complete = false;

        try {
            if ($out !== false && chmod($path, 0600)) {
                $complete = true;
                while ($complete && ! feof($stream)) {
                    $chunk = fread($stream, max(1, config()->integer('wpup.backups.hash_chunk_bytes')));
                    $complete = $chunk !== false && fwrite($out, $chunk) === strlen($chunk);
                    hash_update($hash, (string) $chunk);
                }
                fclose($out);
            }
        } finally {
            fclose($stream);
        }

        if (! $complete || ! hash_equals($row->sha256, hash_final($hash))) {
            @unlink($path);

            throw new RestoreRefused("La còpia #{$row->id} no s'ha pogut llegir sencera o no té el SHA-256 registrat: no es restaura res.");
        }

        return $path;
    }
}
