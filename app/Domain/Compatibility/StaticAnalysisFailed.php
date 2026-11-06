<?php

declare(strict_types=1);

namespace App\Domain\Compatibility;

use RuntimeException;

/**
 * The analysis could not run to the end (no result, which is not "no problems").
 * Messages are stored and shown to operators: no paths of the tool server, no
 * phpcs output verbatim.
 */
final class StaticAnalysisFailed extends RuntimeException
{
    public static function extraction(string $reason): self
    {
        return new self("no s'ha pogut extreure el paquet: {$reason}");
    }

    public static function timeout(int $seconds): self
    {
        return new self("PHPCompatibility ha superat el temps màxim ({$seconds} s).");
    }

    public static function outputTooLarge(): self
    {
        return new self('la sortida de PHPCompatibility supera el límit.');
    }

    public static function crashed(int $exitCode): self
    {
        return new self("PHPCompatibility ha fallat (codi de sortida {$exitCode}).");
    }

    public static function unreadable(): self
    {
        return new self('la sortida de PHPCompatibility no és un informe JSON vàlid.');
    }

    public static function workDir(): self
    {
        return new self("no s'ha pogut preparar el directori de treball.");
    }
}
