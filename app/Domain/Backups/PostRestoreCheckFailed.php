<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use RuntimeException;

/**
 * The copies were restored, but WordPress does not answer as it should
 * afterwards (UZEROWPUP-46). Nothing is undone automatically.
 */
final class PostRestoreCheckFailed extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("La restauració s'ha aplicat, però el lloc no respon bé després: {$reason}.");
    }
}
