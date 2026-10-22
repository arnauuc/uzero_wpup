<?php

declare(strict_types=1);

namespace App\Domain\Sites;

use DomainException;

final class SiteValidationException extends DomainException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(implode("\n", $errors));
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
