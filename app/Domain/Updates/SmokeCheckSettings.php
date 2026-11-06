<?php

declare(strict_types=1);

namespace App\Domain\Updates;

/**
 * Limits and patterns of the minimal layer-3 compatibility check
 * (UZEROWPUP-56); all of them come from config/wpup.php (smoke_check).
 */
final readonly class SmokeCheckSettings
{
    /**
     * @param  list<string>  $fatalPatterns  PCRE patterns; a new log line matching any is a fatal
     */
    public function __construct(
        public int $stepTimeoutSeconds,
        public int $httpTimeoutSeconds,
        public int $maxRedirects,
        public bool $verifyTls,
        public int $logMaxBytes,
        public int $reportedLines,
        public int $lineMaxChars,
        public array $fatalPatterns,
    ) {}
}
