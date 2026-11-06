<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

use RuntimeException;

/**
 * A package artifact could not be obtained or used. Messages are shown to
 * operators and stored in run_steps: they name the host at most, never the
 * URL, and never chain a transport exception (its message carries the URL).
 */
class ArtifactException extends RuntimeException
{
    public static function download(PackageSource $source, string $reason): self
    {
        return new self("No s'ha pogut descarregar el paquet de «{$source->slug}» {$source->version} des de {$source->host()}: {$reason}");
    }

    /** The same message whether the address was refused or did not answer (no port scanning). */
    public static function unreachable(PackageSource $source): self
    {
        return new self("No s'ha pogut baixar el paquet de «{$source->slug}» {$source->version} de {$source->host()}.");
    }

    public static function invalidPackage(PackageSource $source, string $reason): self
    {
        return new self("El paquet de «{$source->slug}» {$source->version} no és vàlid: {$reason}");
    }

    public static function noPackage(string $slug, string $version, string $reason): self
    {
        return new self("No es pot obtenir l'URL del paquet de «{$slug}» {$version}: {$reason}");
    }

    public static function storage(string $reason): self
    {
        return new self("Magatzem d'artefactes: {$reason}");
    }
}
