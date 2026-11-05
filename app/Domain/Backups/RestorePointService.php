<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\AuditResult;
use App\Enums\BackupKind;
use App\Enums\BackupTargetType;
use App\Models\Backup;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Run;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Makes the restore point an update needs (ADR-0009, AGENTS.md rule 3): a
 * validated database dump and the code archive, each with the SHA-256 computed
 * on the site, moved into the site-local store and checked there again, then
 * copied to the site's external destinations, if any, and checked there too.
 * Every file at every destination gets a `backups` row; verified_at is set only
 * when the destination's own checksum matches the source.
 *
 * - The site-local copy is mandatory: any failure there throws BackupFailed,
 *   leaves no verified row and removes what it wrote (best effort, logged).
 * - An external destination that fails is a warning: its row stays unverified
 *   and the restore point is still valid on the site.
 * - Keys are "site-{id}/run{run id}/…" (or a timestamped label without a run).
 *   Run steps are retried after a crash, so for a run it is idempotent: a
 *   complete, verified local point is reused (checked again at the
 *   destination); an incomplete one is discarded first.
 */
final readonly class RestorePointService
{
    private const array OBJECT_NAMES = ['database' => 'db.sql.gz', 'files' => 'files.tar.gz'];

    public function __construct(
        private DatabaseBackup $database,
        private FilesBackup $files,
        private BackupTargetRegistry $registry,
        private SiteLocalDestination $localDestination,
        private SecretRedactor $redactor,
        private HttpFactory $http,
        private SiteLocalSettings $settings,
        private AuditLogger $audit,
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
        $expiresAt = CarbonImmutable::now()->addDays(config()->integer('wpup.backups.retention_days'));

        try {
            $localRecord = $this->localDestination->record();
            $local = new SiteLocalTarget($driver, $this->redactor, $this->http, $this->settings, $site->url);

            $localRows = $run === null ? null : $this->reusable($site, $run, $local, $localRecord, $directory);
            $reused = $localRows !== null;
            $warnings = [];
            if ($localRows === null) {
                if ($run !== null) {
                    $this->discardAttempt($site, $run, $local, $localRecord, $actor, $directory);
                }
                [$localRows, $warnings] = $this->createLocal($site, $driver, $run, $local, $localRecord, $label, $directory, $expiresAt);
            }

            $externalRows = $this->copyToExternal($site, $run, $actor, $local, $localRows, $expiresAt, $warnings);
        } catch (BackupFailed|BackupTargetException|DriverException $e) {
            $failure = $e instanceof BackupFailed ? $e : BackupFailed::incomplete($this->redactor->redact($e->getMessage()), $e);
            $this->audit->record($actor, 'backup.created', AuditResult::Failure, 'site', $site->id, [
                'run_id' => $run?->id,
                'directory' => $directory,
                'reason' => $failure->getMessage(),
            ]);

            throw $failure;
        }

        $point = new RestorePoint($site->id, $run?->id, $directory, [...$localRows, ...$externalRows], $warnings, $reused);
        $this->audit->record($actor, 'backup.created', AuditResult::Success, 'site', $site->id, [
            'run_id' => $run?->id,
            'directory' => $directory,
            'kinds' => array_map(static fn (Backup $b): string => $b->kind->value, $localRows),
            'backup_ids' => array_map(static fn (Backup $b): int => $b->id, $point->backups),
            'external_unverified' => count(array_filter($externalRows, static fn (Backup $b): bool => $b->verified_at === null)),
            'reused' => $point->reused,
        ]);

        return $point;
    }

    /**
     * Dump and archive on the site, moved into the local store and verified.
     *
     * @return array{list<Backup>, list<string>} the verified rows and the warnings of the archive
     *
     * @throws BackupFailed|BackupTargetException|DriverException after removing what it wrote
     */
    private function createLocal(Site $site, SiteDriver $driver, ?Run $run, SiteLocalTarget $local, BackupTargetRecord $localRecord, string $label, string $directory, CarbonImmutable $expiresAt): array
    {
        $rows = [];
        /** @var array<string, RemoteFile> $pending files of the work directory not yet in the store */
        $pending = [];

        try {
            $dump = $this->database->run($driver, $label)->file;
            $pending[BackupKind::Database->value] = $dump;
            $archive = $this->files->run($driver, $site->profile->include_uploads ?? false, $label);
            $pending[BackupKind::Files->value] = $archive->file;

            foreach ([BackupKind::Database, BackupKind::Files] as $kind) {
                $file = $pending[$kind->value];
                $key = "{$directory}/".self::OBJECT_NAMES[$kind->value];
                $stored = $local->adopt($file, $key);
                unset($pending[$kind->value]);
                $row = $this->record($site, $run, $localRecord, $kind, $key, $file->sizeBytes, $file->sha256, $expiresAt);
                $rows[] = $row;
                if ($stored->sizeBytes !== $file->sizeBytes || ! hash_equals($file->sha256, $local->checksum($key))) {
                    throw BackupFailed::notVerified($localRecord->name, $key);
                }
                $row->update(['verified_at' => CarbonImmutable::now()]);
            }
        } catch (BackupFailed|BackupTargetException|DriverException $e) {
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
     * The run's complete local point, if its rows are verified and the store
     * still holds the same bytes.
     *
     * @return list<Backup>|null
     */
    private function reusable(Site $site, Run $run, SiteLocalTarget $local, BackupTargetRecord $localRecord, string $directory): ?array
    {
        $rows = Backup::query()->where('site_id', $site->id)->where('run_id', $run->id)->where('backup_target_id', $localRecord->id)
            ->where('path', 'like', "{$directory}/%")->whereNotNull('verified_at')->whereNull('deleted_at')->orderBy('id')->get();
        $kinds = $rows->map(static fn (Backup $b): string => $b->kind->value)->unique()->sort()->values()->all();
        if ($kinds !== ['database', 'files']) {
            return null;
        }

        foreach ($rows as $row) {
            try {
                if (! hash_equals($row->sha256, $local->checksum($row->path))) {
                    return null;
                }
            } catch (BackupTargetException) {
                return null;
            }
        }

        return array_values($rows->all());
    }

    /**
     * An earlier, incomplete attempt of the same run (a crash, a failure):
     * removes its objects, local and external, and marks its rows deleted. It
     * never passed the gate, so nothing relies on it.
     */
    private function discardAttempt(Site $site, Run $run, SiteLocalTarget $local, BackupTargetRecord $localRecord, AuditActor $actor, string $directory): void
    {
        $rows = Backup::query()->where('site_id', $site->id)->where('run_id', $run->id)->whereNull('deleted_at')->where('path', 'like', "{$directory}/%")->get();
        foreach ($rows as $row) {
            $this->bestEffort('Could not discard an object of an incomplete restore point.', $row->path, function () use ($row, $local, $localRecord, $site, $actor): void {
                $target = $row->backup_target_id === $localRecord->id ? $local : $this->registry->for($row->backupTarget()->firstOrFail(), $site, $actor);
                $target->delete($row->path);
                $row->update(['deleted_at' => CarbonImmutable::now()]);
            });
        }

        // Objects stored before their row was written.
        foreach ($local->list($directory.'/') as $object) {
            $local->delete($object->key);
        }
    }

    /**
     * @param  list<Backup>  $localRows
     * @param  list<string>  $warnings
     * @return list<Backup>
     */
    private function copyToExternal(Site $site, ?Run $run, AuditActor $actor, SiteLocalTarget $local, array $localRows, CarbonImmutable $expiresAt, array &$warnings): array
    {
        $records = $site->backupTargets()->where('backup_targets.type', '!=', BackupTargetType::SiteLocal->value)
            ->where('backup_targets.active', true)->orderBy('backup_targets.id')->get();
        $rows = [];

        foreach ($records as $record) {
            $target = null;
            foreach ($localRows as $source) {
                $row = Backup::query()->firstOrCreate(
                    ['site_id' => $site->id, 'backup_target_id' => $record->id, 'kind' => $source->kind, 'path' => $source->path, 'deleted_at' => null],
                    ['run_id' => $run?->id, 'size_bytes' => $source->size_bytes, 'sha256' => $source->sha256, 'expires_at' => $expiresAt],
                );
                $rows[] = $row;
                if ($row->verified_at !== null) {
                    continue;
                }

                try {
                    $target ??= $this->registry->for($record, $site, $actor);
                    $this->copy($local, $target, $source);
                    $row->update(['verified_at' => CarbonImmutable::now()]);
                } catch (BackupTargetException $e) {
                    $warnings[] = "La còpia externa «{$record->name}» de «{$source->path}» no s'ha verificat: {$this->redactor->redact($e->getMessage())}";
                }
            }
        }

        return $rows;
    }

    /**
     * @throws BackupTargetException when the destination does not hold the source bytes afterwards
     */
    private function copy(SiteLocalTarget $local, BackupTarget $target, Backup $source): void
    {
        // A leftover of an interrupted attempt of this run (keys are per run) with other bytes is replaced.
        if ($target->exists($source->path) && ! hash_equals($source->sha256, $target->checksum($source->path))) {
            $target->delete($source->path);
        }
        if (! $target->exists($source->path)) {
            $stream = $local->readStream($source->path);

            try {
                $target->put($source->path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        if (! hash_equals($source->sha256, $target->checksum($source->path))) {
            $this->bestEffort('Could not remove an external copy that failed verification.', $source->path, static fn () => $target->delete($source->path));

            throw new BackupTargetException("el SHA-256 a la destinació no coincideix amb l'origen");
        }
    }

    private function record(Site $site, ?Run $run, BackupTargetRecord $target, BackupKind $kind, string $key, int $size, string $sha256, CarbonImmutable $expiresAt): Backup
    {
        return Backup::query()->create([
            'site_id' => $site->id,
            'run_id' => $run?->id,
            'backup_target_id' => $target->id,
            'kind' => $kind,
            'path' => $key,
            'size_bytes' => $size,
            'sha256' => $sha256,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Cleanup after a failure: the original failure is what the operator must see.
     *
     * @param  callable(): mixed  $action
     */
    private function bestEffort(string $message, string $what, callable $action): void
    {
        try {
            $action();
        } catch (BackupTargetException|DriverException $e) {
            Log::warning($message, ['key' => $what, 'reason' => $this->redactor->redact($e->getMessage())]);
        }
    }
}
