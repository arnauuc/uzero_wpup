<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

final readonly class PrerequisitesResult
{
    /**
     * @param  list<PrerequisiteCheck>  $checks
     * @param  list<string>  $warnings  redacted, operator-facing: why a check could not be evaluated
     */
    public function __construct(public array $checks, public array $warnings = []) {}
}
