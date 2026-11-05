<?php

declare(strict_types=1);

namespace App\Domain\ToolBackup;

/**
 * The encrypted archive of the tool's backup, in the staging directory, ready
 * to upload. Only ciphertext stays on disk; the caller discards it when done.
 */
final readonly class ToolArchive
{
    /**
     * @param  list<ToolBackupFile>  $files  configuration and reports inside it
     * @param  list<string>  $warnings  paths left out and why
     */
    public function __construct(
        public string $path,
        public string $sha256,
        public int $sizeBytes,
        public int $dumpBytes,
        public array $files,
        public array $warnings,
    ) {}

    public function discard(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
