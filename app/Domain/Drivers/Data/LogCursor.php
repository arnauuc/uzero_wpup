<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Data;

/**
 * Position in the PHP error log. If the log was rotated (inode or size went
 * back), reading starts again from the beginning of the new file.
 */
final readonly class LogCursor
{
    public function __construct(
        public ?string $path,
        public int $offset,
        public ?string $fileId = null,
    ) {}
}
