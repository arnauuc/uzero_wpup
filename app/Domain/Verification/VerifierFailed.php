<?php

declare(strict_types=1);

namespace App\Domain\Verification;

use RuntimeException;

/**
 * The verifier could not verify (not a problem of the site): it crashed, timed
 * out, rejected its input or returned something that is not protocol v1.
 * Messages are operator-facing (Catalan) and never carry the raw output.
 */
final class VerifierFailed extends RuntimeException
{
    public static function timeout(int $seconds): self
    {
        return new self("El verificador no ha acabat en {$seconds} s.");
    }

    public static function rejected(string $detail): self
    {
        return new self("El verificador ha rebutjat l'entrada: {$detail}.");
    }

    public static function crashed(?int $exit, string $detail): self
    {
        return new self('El verificador ha fallat (codi '.($exit ?? '?').')'.($detail === '' ? '.' : ": {$detail}."));
    }

    public static function unreadable(string $detail): self
    {
        return new self("La sortida del verificador no és vàlida: {$detail}.");
    }
}
