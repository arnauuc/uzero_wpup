<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Data;

/**
 * Candidate fatal and warning lines of a region of the PHP error log
 * (UZEROWPUP-56, 71), prefiltered on the site's side in one pass: the caller
 * still classifies them with its own patterns. Notices and deprecations are
 * only counted, never returned.
 */
final readonly class LogScan
{
    /**
     * @param  list<string>  $lines  candidate fatal lines, each cut to a bounded length
     * @param  bool  $capped  there were more fatal candidates than were returned
     * @param  bool  $rotated  the log was rotated or truncated since the cursor
     * @param  bool  $siblingRead  after a rotation, the rotated file (.1) was read from the old offset too
     * @param  list<string>  $warnings  candidate warning lines, each cut to a bounded length
     * @param  int  $notices  how many notice, deprecation and strict-standards lines there were
     * @param  bool  $warningsCapped  there were more warning candidates than were returned
     */
    public function __construct(
        public array $lines = [],
        public bool $capped = false,
        public bool $rotated = false,
        public bool $siblingRead = false,
        public array $warnings = [],
        public int $notices = 0,
        public bool $warningsCapped = false,
    ) {}
}
