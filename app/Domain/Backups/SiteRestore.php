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
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Puts one registered copy back on its site (UZEROWPUP-46).
 *
 * Checksums, one check per moment and path:
 * - before anything is written (RestoreCheckStep), verify() asks the
 *   destination for the SHA-256 of what it holds (computed from its bytes);
 * - when restoring a site-local object, the driver checks size and SHA-256
 *   on the site right before it uses the file, in place (no copy);
 * - when restoring an external object, it is hashed while it is streamed to
 *   the tool server (size and SHA-256 must be the registered ones), and the
 *   driver's upload and restore check the bytes on the site as part of their
 *   contract.
 *
 * Every temporary file on the site matches SiteDriver::RESTORE_TEMP_PATTERN:
 * purgeTemporaries() removes the leftovers of an interrupted restore at the
 * start of the next one, and SiteLocalTarget::purgeStale() the old ones.
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
        $target = $this->target($site, $row, $actor);

        try {
            $actual = $target->checksum($row->path);
        } catch (BackupTargetException $e) {
            throw new RestoreRefused("No s'ha pogut comprovar la còpia #{$row->id} a «{$this->destination($row)}»: {$this->redactor->redact($e->getMessage())}");
        }
        if (! hash_equals($row->sha256, $actual)) {
            throw new RestoreRefused("La còpia #{$row->id} ({$row->path}) a «{$this->destination($row)}» ja no té el SHA-256 registrat.");
        }

        return $target;
    }

    /**
     * What a code archive left out (the paths its restore never touches),
     * read on the site for a site-local object; null when it cannot be read
     * without transferring the archive (external destination).
     *
     * @return list<string>|null
     *
     * @throws DriverException
     */
    public function archiveExcludes(BackupTarget $target, SiteDriver $driver, Backup $row): ?array
    {
        return $target instanceof SiteLocalTarget
            ? $driver->archiveExcludes($target->workFile($row->path, $row->size_bytes, $row->sha256))
            : null;
    }

    /**
     * @throws RestoreRefused before touching the site
     * @throws DriverException when the restore itself fails (the site may be half restored)
     */
    public function restore(Site $site, SiteDriver $driver, Backup $row, AuditActor $actor): void
    {
        $this->prepared($site, $driver, $row, $actor, fn (RemoteFile $file) => $this->apply($driver, $row->kind, $file));
    }

    /**
     * Makes the copy available on the site and hands it to $use: in place
     * for a site-local copy, uploaded (and removed afterwards) for an
     * external one. The database rollback (UZEROWPUP-59) checks the site
     * again right before it imports.
     *
     * @param  Closure(RemoteFile): void  $use
     *
     * @throws RestoreRefused when the copy cannot be read or holds other bytes (nothing written)
     * @throws DriverException from the upload
     * @throws Throwable whatever $use throws
     */
    public function prepared(Site $site, SiteDriver $driver, Backup $row, AuditActor $actor, Closure $use): void
    {
        $target = $this->target($site, $row, $actor);

        if ($target instanceof SiteLocalTarget) {
            $use($target->workFile($row->path, $row->size_bytes, $row->sha256));

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
            $use($file);
        } finally {
            try {
                $driver->remove($name);
            } catch (DriverException $e) {
                // purgeTemporaries() removes it at the next restore.
                Log::warning('Could not remove the uploaded copy of a restore.', ['name' => $name, 'reason' => $this->redactor->redact($e->getMessage())]);
            }
        }
    }

    /**
     * Leftovers of an interrupted restore in the work directory. Only called
     * under the site lock, so no other restore is using them.
     *
     * @throws DriverException
     */
    public function purgeTemporaries(SiteDriver $driver): int
    {
        $removed = 0;
        foreach ($driver->listWorkFiles('') as $file) {
            if (preg_match(SiteDriver::RESTORE_TEMP_PATTERN, $file->path) === 1) {
                $driver->removeWorkFile($file->path);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @throws RestoreRefused
     */
    private function target(Site $site, Backup $row, AuditActor $actor): BackupTarget
    {
        try {
            $record = $row->backupTarget ?? throw new BackupTargetException("La destinació {$row->backup_target_id} no existeix.");

            return $this->registry->for($record, $site, $actor);
        } catch (BackupTargetException $e) {
            throw new RestoreRefused("No s'ha pogut obrir «{$this->destination($row)}» per a la còpia #{$row->id}: {$this->redactor->redact($e->getMessage())}");
        }
    }

    private function destination(Backup $row): string
    {
        return $row->backupTarget->name ?? "#{$row->backup_target_id}";
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
     * The object streamed to a private file of the staging directory, which
     * must hold exactly the registered bytes.
     *
     * @throws RestoreRefused
     */
    private function download(BackupTarget $target, Backup $row): string
    {
        try {
            $stream = $target->readStream($row->path);

            try {
                [$path, $sha256] = StagingCopy::copy($stream, config()->string('wpup.backups.staging_dir'), config()->integer('wpup.backups.hash_chunk_bytes'), 'La lectura', $row->path, $row->size_bytes);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } catch (BackupTargetException $e) {
            throw new RestoreRefused("No s'ha pogut llegir la còpia #{$row->id}: {$this->redactor->redact($e->getMessage())}");
        }

        if (! hash_equals($row->sha256, $sha256)) {
            @unlink($path);

            throw new RestoreRefused("La còpia #{$row->id} no té el SHA-256 registrat a «{$this->destination($row)}».");
        }

        return $path;
    }
}
