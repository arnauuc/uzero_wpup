<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Runs\Exceptions\RunException;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\AuditResult;
use App\Enums\BackupKind;
use App\Models\Backup;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Run;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Makes the restore point an update needs (ADR-0009, AGENTS.md rule 3): a
 * validated database dump and the code archive, each with the SHA-256 computed
 * on the site, moved into the site-local store (its destination, resolved
 * through BackupTargetRegistry) and then copied to the site's external
 * destinations, if any, and checked there. Every file at every destination
 * gets a `backups` row; verified_at is set only when the destination holds the
 * source's bytes.
 *
 * - The site-local copy is mandatory: any failure there throws (BackupFailed
 *   for the domain ones), leaves no verified row and removes what it wrote
 *   (best effort, logged). Every failure, of any kind, is audited.
 * - An external destination that fails is a warning: its row stays unverified
 *   and the restore point is still valid on the site.
 * - Keys are "site-{id}/run{run id}/…" (or a timestamped label without a run).
 *   Run steps are retried after a crash, so for a run it is idempotent: a
 *   complete local point whose bytes are still there is reused; an incomplete
 *   or altered one is discarded first. A destination that cannot be reached
 *   fails the step without discarding anything.
 * - Once the point is made, the retention runs for the site
 *   (RestorePointRetention::enforceAfterCreate): the surplus points go, never
 *   this one.
 */
final readonly class RestorePointService
{
    public function __construct(
        private DatabaseBackup $database,
        private FilesBackup $files,
        private BackupTargetRegistry $registry,
        private SiteLocalDestination $localDestination,
        private SecretRedactor $redactor,
        private AuditLogger $audit,
        private RestorePointRetention $retention,
    ) {}

    /**
     * @param  SiteDriver  $driver  the site's driver; credential reveals were audited as $actor
     *
     * @throws BackupFailed when there is no verified restore point on the site
     */
    public function create(Site $site, SiteDriver $driver, ?Run $run, AuditActor $actor): RestorePoint
    {
        $label = $run === null ? 'manual-'.CarbonImmutable::now()->format('YmdHis').'-'.bin2hex(random_bytes(3)) : "run{$run->id}";
        $directory = "site-{$site->id}/{$label}";

        try {
            $localRecord = $this->localDestination->record();
            $local = $this->localTarget($localRecord, $site, $actor);

            $localRows = $run === null ? null : $this->reusable($site, $run, $local, $localRecord, $directory);
            $reused = $localRows !== null;
            if ($localRows === null) {
                if ($run !== null) {
                    $this->discardAttempt($site, $run, $local, $localRecord, $actor, $directory);
                }
                [$localRows, $warnings] = $this->createLocal($site, $driver, $run, $local, $localRecord, $label, $directory);
            } else {
                // Read-only: the notes of the archive are the same as when it was made.
                $warnings = $this->files->warnings($driver);
            }

            $externalRows = $this->copyToExternal($site, $run, $actor, $local, $localRows, $warnings);
        } catch (Throwable $e) {
            $failure = match (true) {
                $e instanceof BackupFailed => $e,
                $e instanceof BackupTargetException, $e instanceof DriverException => BackupFailed::incomplete($this->redactor->redact($e->getMessage()), $e),
                default => $e,
            };
            $this->auditFailure($actor, $site, $run, $directory, $failure);

            throw $failure;
        }

        $point = new RestorePoint($site->id, $run?->id, $localRecord->id, $directory, [...$localRows, ...$externalRows], $warnings, $reused);
        $this->audit->record($actor, 'backup.created', AuditResult::Success, 'site', $site->id, [
            'run_id' => $run?->id,
            'directory' => $directory,
            'kinds' => array_map(static fn (Backup $b): string => $b->kind->value, $localRows),
            'backup_ids' => array_map(static fn (Backup $b): int => $b->id, $point->backups),
            'external_unverified' => count(array_filter($externalRows, static fn (Backup $b): bool => $b->verified_at === null)),
            'reused' => $point->reused,
        ]);

        // ADR-0009: at most N points per site; never this one. A run holds the site lock already.
        $this->retention->enforceAfterCreate($site, $directory, $actor, callerHoldsLock: $run !== null, targets: [$localRecord->id => $local]);

        return $point;
    }

    public static function objectName(BackupKind $kind): string
    {
        return match ($kind) {
            BackupKind::Database => 'db.sql.gz',
            BackupKind::Files => 'files.tar.gz',
        };
    }

    /**
     * @throws BackupTargetException
     */
    private function localTarget(BackupTargetRecord $record, Site $site, AuditActor $actor): SiteLocalTarget
    {
        $target = $this->registry->for($record, $site, $actor);

        return $target instanceof SiteLocalTarget
            ? $target
            : throw new BackupTargetException("La destinació «{$record->name}» no és el servidor del lloc: no s'hi pot fer el punt de restauració.");
    }

    /**
     * Dump and archive on the site, moved into the local store and verified.
     *
     * @return array{list<Backup>, list<string>} the verified rows and the warnings of the archive
     *
     * @throws Throwable after removing what it wrote
     */
    private function createLocal(Site $site, SiteDriver $driver, ?Run $run, SiteLocalTarget $local, BackupTargetRecord $localRecord, string $label, string $directory): array
    {
        // Nothing is generated for a store the web could serve.
        $local->assertWritable();
        $expiresAt = CarbonImmutable::now()->addDays(config()->integer('wpup.backups.retention_days'));
        $rows = [];
        /** @var array<string, RemoteFile> $pending files of the work directory not yet in the store */
        $pending = [];

        try {
            $pending[BackupKind::Database->value] = $this->database->run($driver, $label)->file;
            $archive = $this->files->run($driver, $site->profile->include_uploads ?? false, $label);
            $pending[BackupKind::Files->value] = $archive->file;

            foreach ([BackupKind::Database, BackupKind::Files] as $kind) {
                $file = $pending[$kind->value];
                $key = "{$directory}/".self::objectName($kind);
                // adopt() hashed the file on the site right before linking it into place.
                $stored = $local->adopt($file, $key);
                unset($pending[$kind->value]);
                $row = Backup::query()->create([
                    'site_id' => $site->id,
                    'run_id' => $run?->id,
                    'backup_target_id' => $localRecord->id,
                    'kind' => $kind,
                    'path' => $key,
                    'size_bytes' => $file->sizeBytes,
                    'sha256' => $file->sha256,
                    'expires_at' => $expiresAt,
                ]);
                $rows[] = $row;
                if ($stored->sizeBytes !== $file->sizeBytes || $stored->sha256 === null || ! hash_equals($file->sha256, $stored->sha256)) {
                    throw BackupFailed::notVerified($localRecord->name, $key);
                }
                $row->update(['verified_at' => CarbonImmutable::now()]);
            }
        } catch (Throwable $e) {
            foreach ($pending as $file) {
                $this->bestEffort('Could not remove a work file of a failed restore point.', $file->path, static fn () => $driver->remove($file->path));
            }
            foreach ($rows as $row) {
                $this->bestEffort('Could not remove an object of a failed restore point.', $row->path, static function () use ($local, $row): void {
                    $local->delete($row->path);
                    $row->update(['deleted_at' => CarbonImmutable::now()]);
                });
            }

            throw $e;
        }

        return [$rows, $archive->warnings];
    }

    /**
     * The run's complete local point, database first, if its rows are verified
     * and the store still holds the same bytes; null if it must be redone.
     *
     * @return list<Backup>|null
     *
     * @throws BackupTargetException when the store cannot be checked (transient: nothing is discarded)
     */
    private function reusable(Site $site, Run $run, SiteLocalTarget $local, BackupTargetRecord $localRecord, string $directory): ?array
    {
        $byKind = [];
        foreach (Backup::query()->where('site_id', $site->id)->where('run_id', $run->id)->where('backup_target_id', $localRecord->id)
            ->where('path', 'like', "{$directory}/%")->whereNotNull('verified_at')->whereNull('deleted_at')->orderBy('id')->get() as $row) {
            $byKind[$row->kind->value] = $row;
        }

        $rows = [];
        foreach (BackupKind::cases() as $kind) {
            $row = $byKind[$kind->value] ?? null;
            if ($row === null) {
                return null;
            }

            try {
                $actual = $local->checksum($row->path);
            } catch (BackupTargetException $e) {
                Log::warning('Could not check a stored restore point before reusing it.', ['key' => $row->path, 'reason' => $this->redactor->redact($e->getMessage())]);
                if ($e->getCode() === BackupTargetException::NOT_FOUND) {
                    return null;
                }

                throw $e;
            }
            if (! hash_equals($row->sha256, $actual)) {
                Log::warning('A stored restore point no longer matches its checksum: it is made again.', ['key' => $row->path]);

                return null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * An earlier, incomplete or altered attempt of the same run: removes its
     * objects, local and external, and marks its rows deleted. It never passed
     * the gate in its current state, so nothing relies on it.
     */
    private function discardAttempt(Site $site, Run $run, SiteLocalTarget $local, BackupTargetRecord $localRecord, AuditActor $actor, string $directory): void
    {
        $rows = Backup::query()->where('site_id', $site->id)->where('run_id', $run->id)->whereNull('deleted_at')->where('path', 'like', "{$directory}/%")->get();
        foreach ($rows as $row) {
            $this->bestEffort('Could not discard an object of an incomplete restore point.', $row->path, function () use ($row, $local, $localRecord, $site, $actor): void {
                if ($row->backup_target_id === $localRecord->id) {
                    $target = $local;
                } else {
                    $record = $row->backupTarget()->first() ?? throw new BackupTargetException("La destinació {$row->backup_target_id} no existeix.");
                    $target = $this->registry->for($record, $site, $actor);
                }
                $target->delete($row->path);
                $row->update(['deleted_at' => CarbonImmutable::now()]);
            }, stopWithRun: true);
        }

        // Objects stored before their row was written.
        foreach ($local->list($directory.'/') as $object) {
            $local->delete($object->key);
        }
    }

    /**
     * Copies the local files to every external destination of the site. A
     * failure, of any kind, is a warning (logged): the point is valid locally.
     *
     * @param  list<Backup>  $localRows
     * @param  list<string>  $warnings
     * @return list<Backup>
     */
    private function copyToExternal(Site $site, ?Run $run, AuditActor $actor, SiteLocalTarget $local, array $localRows, array &$warnings): array
    {
        $rows = [];

        foreach ($site->externalBackupTargets()->get() as $record) {
            $target = null;
            foreach ($localRows as $source) {
                $row = null;

                try {
                    $row = $this->externalRow($site, $run, $record, $source);
                    $rows[] = $row;
                    $target ??= $this->registry->for($record, $site, $actor);
                    $this->copy($local, $target, $source);
                    if ($row->verified_at === null) {
                        $row->update(['verified_at' => CarbonImmutable::now()]);
                    }
                } catch (RunException $e) {
                    // The run itself was stopped (timeout, claim lost): never swallowed.
                    throw $e;
                } catch (Throwable $e) {
                    $reason = $this->redactor->redact($e->getMessage());
                    Log::warning('An external copy of a restore point failed; the point stays valid on the site.', [
                        'destination' => $record->name, 'key' => $source->path, 'exception' => $e::class, 'reason' => $reason,
                    ]);
                    $warnings[] = "La còpia externa «{$record->name}» de «{$source->path}» no s'ha verificat: "
                        .($e instanceof BackupTargetException || $e instanceof DriverException ? $reason : 'error inesperat (vegeu el log)');
                    if ($row?->verified_at !== null) {
                        $this->bestEffort('Could not mark an external copy as unverified.', $source->path, static fn () => $row->update(['verified_at' => null]));
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * The row of $source at $record, tied to the current source's checksum,
     * size and expiry: a row of an earlier attempt with other bytes is updated
     * and stops counting as verified.
     */
    private function externalRow(Site $site, ?Run $run, BackupTargetRecord $record, Backup $source): Backup
    {
        $attributes = ['run_id' => $run?->id, 'size_bytes' => $source->size_bytes, 'sha256' => $source->sha256, 'expires_at' => $source->expires_at];
        $row = Backup::query()->where('site_id', $site->id)->where('backup_target_id', $record->id)->where('kind', $source->kind)
            ->where('path', $source->path)->whereNull('deleted_at')->latest('id')->first();

        if ($row === null) {
            return Backup::query()->create(['site_id' => $site->id, 'backup_target_id' => $record->id, 'kind' => $source->kind, 'path' => $source->path] + $attributes);
        }
        if ($row->sha256 !== $source->sha256 || $row->size_bytes !== $source->size_bytes || $row->expires_at?->toIso8601String() !== $source->expires_at?->toIso8601String()) {
            $row->update($attributes + ['verified_at' => null]);
        }

        return $row;
    }

    /**
     * Leaves at $target exactly the source's bytes under its key, checked with
     * the destination's own checksum.
     *
     * @throws BackupTargetException
     */
    private function copy(SiteLocalTarget $local, BackupTarget $target, Backup $source): void
    {
        if ($target->exists($source->path)) {
            if (hash_equals($source->sha256, $target->checksum($source->path))) {
                return;
            }
            // A leftover of an interrupted attempt of this run (keys are per run) with other bytes.
            $target->delete($source->path);
        }

        $stream = $local->readStream($source->path);

        try {
            $target->put($source->path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! hash_equals($source->sha256, $target->checksum($source->path))) {
            $this->bestEffort('Could not remove an external copy that failed verification.', $source->path, static fn () => $target->delete($source->path));

            throw new BackupTargetException("el SHA-256 a la destinació no coincideix amb l'origen");
        }
    }

    private function auditFailure(AuditActor $actor, Site $site, ?Run $run, string $directory, Throwable $failure): void
    {
        try {
            $this->audit->record($actor, 'backup.created', AuditResult::Failure, 'site', $site->id, [
                'run_id' => $run?->id,
                'directory' => $directory,
                'exception' => $failure::class,
                'reason' => $this->redactor->redact($failure->getMessage()),
            ]);
        } catch (Throwable $e) {
            // The failure itself is what the caller must see.
            Log::error('Could not audit a failed restore point.', ['site_id' => $site->id, 'run_id' => $run?->id, 'reason' => $this->redactor->redact($e->getMessage())]);
        }
    }

    /**
     * Cleanup: never masks the failure being handled. Outside a failure
     * ($stopWithRun), a stopped run (timeout, claim lost) still stops.
     *
     * @param  callable(): mixed  $action
     */
    private function bestEffort(string $message, string $what, callable $action, bool $stopWithRun = false): void
    {
        try {
            $action();
        } catch (Throwable $e) {
            if ($stopWithRun && $e instanceof RunException) {
                throw $e;
            }
            Log::warning($message, ['key' => $what, 'exception' => $e::class, 'reason' => $this->redactor->redact($e->getMessage())]);
        }
    }
}
