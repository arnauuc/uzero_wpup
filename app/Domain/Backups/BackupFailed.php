<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use RuntimeException;
use Throwable;

/**
 * A restore-point backup could not be made or did not pass validation. Messages
 * are shown to operators and never contain secrets.
 */
final class BackupFailed extends RuntimeException
{
    /**
     * @param  list<string>  $missing
     */
    public static function missingTables(array $missing, bool $dumpRemoved): self
    {
        $shown = array_slice($missing, 0, 20);
        $more = count($missing) - count($shown);

        return new self(sprintf(
            'El volcat de la base de dades no conté totes les taules: falten %d (%s%s). %s',
            count($missing),
            implode(', ', $shown),
            $more > 0 ? " i {$more} més" : '',
            $dumpRemoved ? 'S\'ha esborrat el volcat.' : 'No s\'ha pogut esborrar el volcat: queda al directori de treball del lloc.',
        ));
    }

    public static function noTables(): self
    {
        return new self('El lloc no té cap taula amb el prefix de WordPress: no es pot fer la còpia de la base de dades.');
    }

    public static function invalidFileName(string $name): self
    {
        return new self("El nom de fitxer remot de la còpia no és vàlid: «{$name}».");
    }

    public static function notVerified(string $destination, string $key): self
    {
        return new self("La còpia «{$key}» no supera la verificació a la destinació «{$destination}» (la mida o el SHA-256 no coincideixen amb l'origen): no hi ha punt de restauració.");
    }

    /**
     * @param  string  $reason  already redacted
     */
    public static function incomplete(string $reason, ?Throwable $previous = null): self
    {
        return new self("No s'ha pogut fer el punt de restauració: {$reason}", 0, $previous);
    }
}
