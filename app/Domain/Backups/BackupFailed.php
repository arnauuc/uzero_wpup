<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use RuntimeException;

/**
 * A restore-point backup could not be made or did not pass validation. Messages
 * are shown to operators and never contain secrets.
 */
final class BackupFailed extends RuntimeException
{
    /**
     * @param  list<string>  $missing
     */
    public static function missingTables(array $missing): self
    {
        $shown = array_slice($missing, 0, 20);
        $more = count($missing) - count($shown);

        return new self(sprintf(
            'El volcat de la base de dades no conté totes les taules: falten %d (%s%s). S\'ha esborrat el volcat.',
            count($missing),
            implode(', ', $shown),
            $more > 0 ? " i {$more} més" : '',
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
}
