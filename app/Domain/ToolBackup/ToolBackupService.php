<?php

declare(strict_types=1);

namespace App\Domain\ToolBackup;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetException;
use App\Domain\Backups\BackupTargetRegistry;
use App\Enums\AuditResult;
use App\Enums\BackupTargetType;
use App\Models\BackupTarget as BackupTargetRecord;
use DateTimeInterface;

/**
 * The tool's own daily backup (ADR-0009 §3, UZEROWPUP-47): the encrypted
 * archive (ToolArchiveBuilder) uploaded to the tool's Dropbox destination,
 * its SHA-256 checked there, then the retention applied. Runs on the workers
 * only: opening the destination reveals its credential (ADR-0011).
 *
 * Audit trail (insert-only): one `tool_backup.deleted` per copy the retention
 * removes, and a `tool_backup.completed` or `tool_backup.failed` summary per
 * run. Never any secret: only keys, sizes, checksums and paths.
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
     * the retention would then delete. Changes nothing.
     *
     * @throws ToolBackupException
     */
    public function preview(AuditActor $actor, DateTimeInterface $at): ToolBackupPreview
    {
        $recipients = $this->settings->validRecipients();
        [$files, $warnings] = $this->contents->collect([...$this->settings->configPaths, ...$this->settings->reportPaths]);
        [$record, $target] = $this->target($actor);
        $key = ToolBackupKey::for($at);

        try {
            $existing = $this->keys($target);
        } catch (BackupTargetException $e) {
            throw new ToolBackupException($e->getMessage());
        }

        return new ToolBackupPreview($record->name, $key, $recipients, $files, $warnings, count($existing), $this->retention()->plan([...$existing, $key])->delete);
    }

    /**
     * @throws ToolBackupException
     */
    public function run(AuditActor $actor, DateTimeInterface $at): ToolBackupResult
    {
        $record = null;

        try {
            [$record, $target] = $this->target($actor);
            $key = ToolBackupKey::for($at);
            $archive = $this->builder->build($at);

            try {
                $this->upload($target, $key, $archive);
            } finally {
                $archive->discard();
            }

            [$deleted, $kept, $warnings] = $this->applyRetention($target, $record, $key, $actor);
        } catch (ToolBackupException|BackupTargetException $e) {
            $this->audit->record($actor, 'tool_backup.failed', AuditResult::Failure, 'backup_target', $record?->id, [
                'target' => $record->name ?? $this->targetName,
                'reason' => $e->getMessage(),
            ]);

            throw $e instanceof ToolBackupException ? $e : new ToolBackupException($e->getMessage(), previous: $e);
        }

        $result = new ToolBackupResult($record->name, $key, $archive->sizeBytes, $archive->sha256, $archive->dumpBytes,
            array_map(static fn (ToolBackupFile $f): string => $f->source, $archive->files), [...$archive->warnings, ...$warnings], $deleted, $kept);
        $this->audit->record($actor, 'tool_backup.completed', AuditResult::Success, 'backup_target', $record->id, $result->toArray());

        return $result;
    }

    /**
     * @return array{BackupTargetRecord, BackupTarget}
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
     * @throws ToolBackupException|BackupTargetException
     */
    private function upload(BackupTarget $target, string $key, ToolArchive $archive): void
    {
        $stream = fopen($archive->path, 'rb');
        if ($stream === false) {
            throw new ToolBackupException("No s'ha pogut obrir la còpia xifrada per pujar-la.");
        }

        try {
            $target->put($key, $stream);
        } finally {
            fclose($stream);
        }

        // What the destination holds, from its own bytes (BackupTarget::checksum()).
        if (! hash_equals($archive->sha256, $target->checksum($key))) {
            $target->delete($key);

            throw new ToolBackupException("El SHA-256 de «{$key}» a la destinació no coincideix amb el local: s'ha esborrat la còpia pujada.");
        }
    }

    /**
     * @return array{list<string>, int, list<string>} deleted keys, copies kept, warnings
     *
     * @throws BackupTargetException when the destination cannot be listed
     */
    private function applyRetention(BackupTarget $target, BackupTargetRecord $record, string $newKey, AuditActor $actor): array
    {
        $plan = $this->retention()->plan($this->keys($target));
        $deleted = [];
        $warnings = [];

        foreach ($plan->delete as $key) {
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
     * @return list<string>
     *
     * @throws BackupTargetException
     */
    private function keys(BackupTarget $target): array
    {
        return array_map(static fn ($object): string => $object->key, $target->list(ToolBackupKey::PREFIX));
    }

    private function retention(): ToolBackupRetention
    {
        return new ToolBackupRetention($this->settings->dailyCopies, $this->settings->monthlyCopies);
    }
}
