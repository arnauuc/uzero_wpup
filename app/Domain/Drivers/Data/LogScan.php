<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Data;

/**
 * Candidate fatal lines of the PHP error log (UZEROWPUP-56), prefiltered on
 * the site's side: the caller still classifies them with its own patterns.
 */
final readonly class LogScan
{
    /**
     * @param  list<string>  $lines  candidate lines, each cut to a bounded length
     * @param  bool  $capped  there were more candidates than were returned
     * @param  bool  $rotated  the log was rotated or truncated since the cursor
     * @param  bool  $siblingRead  after a rotation, the rotated file (.1) was read from the old offset too
     */
    public function __construct(
        public array $lines = [],
        public bool $capped = false,
        public bool $rotated = false,
        public bool $siblingRead = false,
    ) {}
}
