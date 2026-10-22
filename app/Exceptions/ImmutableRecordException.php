<?php

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

final class ImmutableRecordException extends LogicException
{
    public static function forModel(string $model, string $operation): self
    {
        return new self(sprintf('%s is insert-only; %s is not allowed.', $model, $operation));
    }
}
