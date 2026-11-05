<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Data;

/**
 * Where the tool's work area is on the site: physical paths (symlinks
 * resolved) of the account's home and of the work directory.
 */
final readonly class WorkArea
{
    public function __construct(
        public string $home,
        public string $workDir,
    ) {}
}
