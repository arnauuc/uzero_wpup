<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use RuntimeException;

/**
 * Failures of a backup destination. Messages never contain secrets.
 */
final class BackupTargetException extends RuntimeException
{
    /** Code of notFound(): the object is not there (as opposed to a failure reaching the destination). */
    public const int NOT_FOUND = 404;

    public static function invalidKey(string $key): self
    {
        return new self(sprintf('Clau de còpia no vàlida: «%s» (ha de ser una ruta relativa, sense «..»).', mb_substr($key, 0, 200)));
    }

    public static function alreadyExists(string $key): self
    {
        return new self("La còpia «{$key}» ja existeix a la destinació: les còpies no se sobreescriuen.");
    }

    public static function notFound(string $key): self
    {
        return new self("La còpia «{$key}» no existeix a la destinació.", self::NOT_FOUND);
    }

    public static function notAStream(): self
    {
        return new self('La còpia ha de rebre un flux de dades llegible.');
    }

    public static function unregistered(string $type): self
    {
        return new self("No hi ha cap destinació de còpia registrada per al tipus «{$type}».");
    }

    public static function failed(string $what, string $key, string $reason): self
    {
        return new self("{$what} de «{$key}» ha fallat: {$reason}");
    }
}
