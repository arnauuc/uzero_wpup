<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Data;

use App\Enums\CheckResult;

/**
 * One prerequisite checked by `site:test`, with an operator-readable detail.
 */
final readonly class ProbeCheck
{
    public function __construct(
        public string $name,
        public CheckResult $result,
        public string $detail,
    ) {}
}
