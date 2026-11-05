<?php

declare(strict_types=1);

namespace App\Domain\ToolBackup;

/**
 * A tool backup that was uploaded and verified, and what the retention did.
 */
final readonly class ToolBackupResult
{
    /**
     * @param  list<string>  $files  configuration and reports included
     * @param  list<string>  $warnings  paths left out, deletions that failed
     * @param  list<string>  $deleted  copies removed by the retention
     */
    public function __construct(
        public string $target,
        public string $key,
        public int $sizeBytes,
        public string $sha256,
        public int $dumpBytes,
        public array $files,
        public array $warnings,
        public array $deleted,
        public int $kept,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'key' => $this->key,
            'size_bytes' => $this->sizeBytes,
            'sha256' => $this->sha256,
            'dump_bytes' => $this->dumpBytes,
            'files' => $this->files,
            'warnings' => $this->warnings,
            'deleted' => $this->deleted,
            'kept' => $this->kept,
        ];
    }

    public function summary(): string
    {
        return "Còpia «{$this->key}» pujada a «{$this->target}» i verificada (SHA-256 ".substr($this->sha256, 0, 12).'); '
            ."se'n conserven {$this->kept} i se n'han esborrat ".count($this->deleted).'.'
            .($this->warnings === [] ? '' : ' Avisos: '.count($this->warnings).'.');
    }
}
