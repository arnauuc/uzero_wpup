<?php

declare(strict_types=1);

namespace App\Domain\Scheduling;

/**
 * Free resources of the tool's own server (never a site's): what the
 * scheduler checks before each site (ADR-0006 §2). Null when unknown.
 */
interface ToolResources
{
    public function freeDiskBytes(string $path): ?int;

    public function availableMemoryBytes(): ?int;
}
