<?php

declare(strict_types=1);

namespace App\Domain\ToolBackup;

/**
 * A file of the tool server that goes into its backup, and where inside the
 * archive (`files/` + its absolute path).
 */
final readonly class ToolBackupFile
{
    public function __construct(
        public string $source,
        public string $archivePath,
        public int $sizeBytes,
    ) {}
}
