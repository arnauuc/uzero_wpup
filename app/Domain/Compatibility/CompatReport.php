<?php

declare(strict_types=1);

namespace App\Domain\Compatibility;

/**
 * Result of the analysis of one package for one PHP minor. The counts are the
 * real ones; the findings are capped (errors first).
 */
final readonly class CompatReport
{
    /**
     * @param  list<CompatFinding>  $findings
     */
    public function __construct(
        public int $errors,
        public int $warnings,
        public array $findings,
        /** True when it came from the cache (the package was not analysed again). */
        public bool $cached = false,
    ) {}

    /**
     * @return list<CompatFinding>
     */
    public function findingsOf(bool $errors): array
    {
        return array_values(array_filter($this->findings, static fn (CompatFinding $f): bool => $f->isError === $errors));
    }
}
