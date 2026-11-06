<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * A destination that can give an object a new key without copying it through
 * the tool. The tool's own backup (UZEROWPUP-47) uploads under a pending key
 * and only promotes it to its final key once the SHA-256 at the destination
 * matches, so a final key is always a verified copy.
 */
interface RenamableBackupTarget extends BackupTarget
{
    /**
     * @throws BackupTargetException when $from does not exist, $to exists or the move fails
     */
    public function rename(string $from, string $to): void;
}
