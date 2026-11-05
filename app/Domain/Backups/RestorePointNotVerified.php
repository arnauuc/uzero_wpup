<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use RuntimeException;

/**
 * A run has no verified restore point on the site (AGENTS.md rule 3): it must
 * not update. The message is shown to operators.
 */
final class RestorePointNotVerified extends RuntimeException
{
    public static function forRun(int $runId): self
    {
        return new self("L'execució {$runId} no té cap punt de restauració verificat (volcat de la base de dades i arxiu del codi, amb el SHA-256 comprovat a la destinació local): no s'actualitza.");
    }
}
