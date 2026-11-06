<?php

declare(strict_types=1);

namespace App\Domain\ToolBackup;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Backups\BackupTargetException;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\RenamableBackupTarget;
use App\Domain\Backups\StoredObject;
use App\Enums\AuditResult;
use App\Enums\BackupTargetType;
use App\Models\BackupTarget as BackupTargetRecord;
use DateTimeInterface;
use Throwable;

/**
 * The tool's own daily backup (ADR-0009 §3, UZEROWPUP-47): the encrypted
 * archive (ToolArchiveBuilder) uploaded to the tool's Dropbox destination
 * under a pending key, promoted to its final key only once its SHA-256 at the
 * destination matches, then the retention applied. Runs on the workers only:
 * opening the destination reveals its credential (ADR-0011).
 *
 * A final key (ToolBackupKey::for()) is therefore always a verified copy, and
 * retention only ever counts final keys. Pending uploads a failed run could
 * not remove are removed by the next run.
 *
 * Audit trail (insert-only): one `tool_backup.deleted` per object removed,
 * `tool_backup.cleanup_failed` when a failed upload could not be removed, and
 * a `tool_backup.completed` or `tool_backup.failed` summary for every check
 * and every run, whatever went wrong. Never any secret: only keys, sizes,
 * checksums and paths.
 */
final readonly class ToolBackupService
{
    public function __construct(
        private ToolArchiveBuilder $builder,
        private ToolBackupContents $contents,
        private ToolBackupSettings $settings,
        private BackupTargetRegistry $registry,
        private AuditLogger $audit,
        private string $targetName,
    ) {}

    /**
     * What a backup made at $at would hold, where it would go and which copies
     * the retention would then delete. Changes nothing but the audit trail
     * when it fails.
     *
     * @throws ToolBackupException
     * @throws Throwable unexpected failures, audited and rethrown
     */
    public function preview(AuditActor $actor, DateTimeInterface $at): ToolBackupPreview
    {
        $record = null;

        try {
            $recipients = $this->settings->validRecipients();
            [$files, $warnings] = $this->contents->collect([...$this->settings->configPaths, ...$this->settings->reportPaths]);
            [$record, $target] = $this->target($actor);
            $key = ToolBackupKey::for($at);
            $existing = $this->finalKeys($target->list(ToolBackupKey::PREFIX));

            return new ToolBackupPreview($record->name, $key, $recipients, $files, $warnings, count($existing), $this->retention()->plan([...$existing, $key])->delete);
        } catch (Throwable $e) {
            throw $this->failed($actor, $record, 'check', $e);
        }
    }

    /**
     * @throws ToolBackupException
     * @throws Throwable unexpected failures, audited and rethrown
     */
    public function run(AuditActor $actor, DateTimeInterface $at): ToolBackupResult
    {
        $record = null;

        try {
            [$record, $target] = $this->target($actor);
            $key = ToolBackupKey::for($at);
            $archive = $this->builder->build($at);

            try {
                $this->upload($target, $record, $key, ToolBackupKey::pendingFor($at), $archive, $actor);
            } finally {
                $archive->discard();
            }

            [$deleted, $kept, $warnings] = $this->applyRetention($target, $record, $key, $actor);
        } catch (Throwable $e) {
            throw $this->failed($actor, $record, 'backup', $e);
        }

        $result = new ToolBackupResult($record->name, $key, $archive->sizeBytes, $archive->sha256, $archive->dumpBytes,
            array_map(static fn (ToolBackupFile $f): string => $f->source, $archive->files), [...$archive->warnings, ...$warnings], $deleted, $kept);
        $this->audit->record($actor, 'tool_backup.completed', AuditResult::Success, 'backup_target', $record->id, $result->toArray());

        return $result;
    }

    /**
     * Audits the failure and returns what to throw: domain failures as
     * ToolBackupException, anything else as it was.
     */
    private function failed(AuditActor $actor, ?BackupTargetRecord $record, string $phase, Throwable $e): Throwable
    {
        $known = $e instanceof ToolBackupException || $e instanceof BackupTargetException;
        $this->audit->record($actor, 'tool_backup.failed', AuditResult::Failure, 'backup_target', $record?->id, [
            'target' => $record->name ?? $this->targetName,
            'phase' => $phase,
            'reason' => $known ? $e->getMessage() : 'Error inesperat ('.$e::class.'): '.$e->getMessage(),
        ]);

        return match (true) {
            $e instanceof ToolBackupException => $e,
            $e instanceof BackupTargetException => new ToolBackupException($e->getMessage(), previous: $e),
            default => $e,
        };
    }

    /**
     * @return array{BackupTargetRecord, RenamableBackupTarget}
     *
     * @throws ToolBackupException|BackupTargetException
     */
    private function target(AuditActor $actor): array
    {
        $record = BackupTargetRecord::query()->where('name', $this->targetName)->first();
        if ($record === null) {
            throw new ToolBackupException("No hi ha cap destinació de còpia «{$this->targetName}» per a la còpia de l'eina (vegeu docs/runbooks/copia-eina.md).");
        }
        if ($record->type !== BackupTargetType::Dropbox) {
            throw new ToolBackupException("La destinació «{$record->name}» de la còpia de l'eina ha de ser de Dropbox (ADR-0009), no {$record->type->value}.");
        }

        return [$record, $this->registry->forTool($record, $actor)];
    }

    /**
     * Uploads under $pending, checks the SHA-256 the destination computes from
     * its own bytes, and only then renames it to $key. On any failure the
     * pending object is removed (best effort, audited when it cannot be).
     *
     * @throws ToolBackupException|BackupTargetException
     */
    private function upload(RenamableBackupTarget $target, BackupTargetRecord $record, string $key, string $pending, ToolArchive $archive, AuditActor $actor): void
    {
        $stream = fopen($archive->path, 'rb');
        if ($stream === false) {
            throw new ToolBackupException("No s'ha pogut obrir la còpia xifrada per pujar-la.");
        }

        try {
            try {
                $target->put($pending, $stream);
            } finally {
                fclose($stream);
            }
            if (! hash_equals($archive->sha256, $target->checksum($pending))) {
                throw new ToolBackupException("El SHA-256 de la còpia pujada no coincideix amb el local: no s'ha desat com a «{$key}».");
            }
            $target->rename($pending, $key);
        } catch (Throwable $e) {
            $this->discardPending($target, $record, $pending, $actor);

            throw $e;
        }
    }

    private function discardPending(RenamableBackupTarget $target, BackupTargetRecord $record, string $pending, AuditActor $actor): void
    {
        try {
            $target->delete($pending);
        } catch (Throwable $e) {
            // The next run removes it (applyRetention); it is never a final key.
            $this->audit->record($actor, 'tool_backup.cleanup_failed', AuditResult::Failure, 'backup_target', $record->id, [
                'target' => $record->name,
                'key' => $pending,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Deletes what the retention does not keep, and the pending uploads of
     * earlier runs that failed (there is one run at a time: the current one
     * is already promoted).
     *
     * @return array{list<string>, int, list<string>} deleted keys, copies kept, warnings
     *
     * @throws BackupTargetException when the destination cannot be listed
     */
    private function applyRetention(RenamableBackupTarget $target, BackupTargetRecord $record, string $newKey, AuditActor $actor): array
    {
        $objects = $target->list(ToolBackupKey::PREFIX);
        $plan = $this->retention()->plan($this->finalKeys($objects));
        $pending = array_values(array_filter(array_map(static fn (StoredObject $o): string => $o->key, $objects), static fn (string $k): bool => str_starts_with($k, ToolBackupKey::PENDING_PREFIX)));
        $deleted = [];
        $warnings = [];

        foreach ([...$pending, ...$plan->delete] as $key) {
            // The copy just made is always the most recent: never deleted.
            if ($key === $newKey) {
                continue;
            }

            try {
                $target->delete($key);
            } catch (BackupTargetException $e) {
                $warnings[] = "No s'ha pogut esborrar «{$key}»: {$e->getMessage()}";
                $this->audit->record($actor, 'tool_backup.deleted', AuditResult::Failure, 'backup_target', $record->id, ['target' => $record->name, 'key' => $key, 'reason' => $e->getMessage()]);

                continue;
            }
            $deleted[] = $key;
            $this->audit->record($actor, 'tool_backup.deleted', AuditResult::Success, 'backup_target', $record->id, ['target' => $record->name, 'key' => $key]);
        }

        return [$deleted, count($plan->keep), $warnings];
    }

    /**
     * The keys of verified copies: final keys only, never pending uploads.
     *
     * @param  list<StoredObject>  $objects
     * @return list<string>
     */
    private function finalKeys(array $objects): array
    {
        return array_values(array_filter(
            array_map(static fn (StoredObject $o): string => $o->key, $objects),
            static fn (string $key): bool => ToolBackupKey::timeOf($key) !== null,
        ));
    }

    private function retention(): ToolBackupRetention
    {
        return new ToolBackupRetention($this->settings->dailyCopies, $this->settings->monthlyCopies);
    }
}
