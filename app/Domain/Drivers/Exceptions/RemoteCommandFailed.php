<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Exceptions;

use App\Domain\Drivers\Data\CommandResult;

final class RemoteCommandFailed extends DriverException
{
    public function __construct(string $message, public readonly ?CommandResult $result = null)
    {
        parent::__construct($message);
    }
}
