<?php

declare(strict_types=1);

namespace App\Domain\Scheduling;

use Illuminate\Support\Facades\Log;

/**
 * Whether the tool's server has enough free disk and memory to start a site
 * (ADR-0006 §2: the scheduler stops and warns instead of starting a site it
 * could not finish). A value it cannot read is logged, not a stop: the run's
 * own checks still apply.
 */
final readonly class ResourceCheck
{
    private const int MB = 1024 * 1024;

    public function __construct(
        private ToolResources $resources,
        private string $diskPath,
        private int $minFreeDiskMb,
        private int $minFreeMemoryMb,
    ) {}

    /**
     * Why nothing may start (operator-facing); empty when there is enough.
     *
     * @return list<string>
     */
    public function shortages(): array
    {
        $shortages = [];
        $disk = $this->resources->freeDiskBytes($this->diskPath);
        $memory = $this->resources->availableMemoryBytes();
        if ($disk === null || $memory === null) {
            Log::warning('The scheduler could not read the free resources of the tool server.', ['disk' => $disk, 'memory' => $memory]);
        }
        if ($disk !== null && $disk < $this->minFreeDiskMb * self::MB) {
            $shortages[] = sprintf('Només queden %d MB de disc lliures a %s (mínim: %d MB).', intdiv($disk, self::MB), $this->diskPath, $this->minFreeDiskMb);
        }
        if ($memory !== null && $memory < $this->minFreeMemoryMb * self::MB) {
            $shortages[] = sprintf('Només hi ha %d MB de memòria disponibles (mínim: %d MB).', intdiv($memory, self::MB), $this->minFreeMemoryMb);
        }

        return $shortages;
    }
}
