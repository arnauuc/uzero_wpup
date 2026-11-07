<?php

declare(strict_types=1);

namespace App\Domain\Verification;

use RuntimeException;
use Throwable;

/**
 * The verifier could not verify (not a problem of the site): it is not
 * installed, crashed, timed out, rejected its input or returned something that
 * is not protocol v1. Messages are operator-facing (Catalan), already redacted,
 * and never carry the raw output.
 */
final class VerifierFailed extends RuntimeException
{
    public static function notInstalled(string $detail): self
    {
        return new self("El verificador no està instal·lat en aquest entorn: {$detail} (docs/verificador.md, requisits).");
    }

    public static function workDir(string $reason, Throwable $previous): self
    {
        return new self("No s'ha pogut preparar el directori de sortida del verificador: {$reason}", 0, $previous);
    }

    public static function timeout(int $seconds): self
    {
        return new self("El verificador no ha acabat en {$seconds} s i s'ha aturat.");
    }

    public static function outputTooLarge(string $stream, int $bytes): self
    {
        return new self("El verificador ha escrit més de {$bytes} bytes a {$stream} i s'ha aturat.");
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
