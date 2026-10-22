<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Exceptions;

final class IntegrityCheckFailed extends DriverException
{
    public static function checksumMismatch(string $path): self
    {
        return new self(sprintf('El checksum de «%s» no coincideix després de transferir-lo.', $path));
    }
}
