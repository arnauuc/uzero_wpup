<?php

declare(strict_types=1);

namespace App\Domain\Scheduling;

/**
 * The tool server's free disk (disk_free_space) and available memory
 * (MemAvailable of /proc/meminfo: what can be used without swapping).
 */
final readonly class SystemToolResources implements ToolResources
{
    public function __construct(private string $meminfo = '/proc/meminfo') {}

    public function freeDiskBytes(string $path): ?int
    {
        $free = @disk_free_space($path);

        return $free === false ? null : (int) $free;
    }

    public function availableMemoryBytes(): ?int
    {
        $content = is_readable($this->meminfo) ? @file_get_contents($this->meminfo) : false;
        if ($content === false || preg_match('/^MemAvailable:\s+(\d+)\s+kB$/m', $content, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 1024;
    }
}
