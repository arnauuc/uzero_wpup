<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Enums\SkipReason;

/**
 * What an analysis of a planned update found: a reason to skip it, or only
 * warnings for the report.
 */
final readonly class ChangeAnalysis
{
    /**
     * @param  list<string>  $warnings  "file:line message", already capped
     */
    public function __construct(
        public ?SkipReason $skip = null,
        public ?string $detail = null,
        public array $warnings = [],
    ) {}
}
