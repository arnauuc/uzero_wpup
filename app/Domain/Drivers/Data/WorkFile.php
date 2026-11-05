<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Data;

/**
 * A regular file in the tool's work area on a site, as the site sees it.
 */
final readonly class WorkFile
{
    /**
     * @param  string  $path  WorkPath relative to the work area
     * @param  int|null  $modifiedAt  Unix time, when known
     * @param  string|null  $sha256  computed on the site, only when asked for
     */
    public function __construct(
        public string $path,
        public int $sizeBytes,
        public ?int $modifiedAt = null,
        public ?string $sha256 = null,
    ) {}
}
