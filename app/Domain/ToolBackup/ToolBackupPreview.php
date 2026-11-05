<?php

declare(strict_types=1);

namespace App\Domain\ToolBackup;

/**
 * What a tool backup would do now (also its --dry-run).
 */
final readonly class ToolBackupPreview
{
    /**
     * @param  list<string>  $recipients  age public keys (not secret)
     * @param  list<ToolBackupFile>  $files
     * @param  list<string>  $warnings
     * @param  list<string>  $wouldDelete
     */
    public function __construct(
        public string $target,
        public string $key,
        public array $recipients,
        public array $files,
        public array $warnings,
        public int $existing,
        public array $wouldDelete,
    ) {}

    /**
     * @return array{target: string, key: string, recipients: list<string>, files: list<string>, warnings: list<string>, existing: int, would_delete: list<string>}
     */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'key' => $this->key,
            'recipients' => $this->recipients,
            'files' => array_map(static fn (ToolBackupFile $f): string => $f->source, $this->files),
            'warnings' => $this->warnings,
            'existing' => $this->existing,
            'would_delete' => $this->wouldDelete,
        ];
    }

    public function summary(): string
    {
        return "Còpia «{$this->key}» a «{$this->target}»: la BD i ".count($this->files).' fitxers, per a '.count($this->recipients).' destinatari(s) d\'age; '
            ."hi ha {$this->existing} còpies i la retenció n'esborraria ".count($this->wouldDelete).'.';
    }
}
