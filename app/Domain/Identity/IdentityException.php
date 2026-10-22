<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use RuntimeException;

/**
 * The caller could not be identified as an active operator. Messages are shown to
 * the operator, so they are in Catalan.
 */
final class IdentityException extends RuntimeException
{
    public static function notThroughSudo(string $serviceUser): self
    {
        return new self(sprintf("Executa l'eina com a operador: sudo -u %s wpup ... (SUDO_USER no és definit).", $serviceUser));
    }

    public static function wrongServiceUser(string $effective, string $serviceUser): self
    {
        return new self(sprintf("L'eina ha de córrer com a usuari %s, no com a %s.", $serviceUser, $effective));
    }

    public static function unknownUser(string $unixUser): self
    {
        return new self(sprintf("L'usuari Unix «%s» no està donat d'alta a l'eina.", $unixUser));
    }

    public static function inactiveUser(string $unixUser): self
    {
        return new self(sprintf("L'usuari «%s» està desactivat.", $unixUser));
    }

    public static function posixModeInProduction(): self
    {
        return new self('El mode d\'identitat "posix" és només per a desenvolupament; a producció cal "sudo".');
    }

    public static function unknownMode(string $mode): self
    {
        return new self(sprintf('Mode d\'identitat desconegut: "%s".', $mode));
    }
}
