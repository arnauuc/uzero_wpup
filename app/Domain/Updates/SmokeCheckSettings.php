<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use InvalidArgumentException;

/**
 * Limits and patterns of the minimal layer-3 compatibility check
 * (UZEROWPUP-56); all of them come from config/wpup.php (smoke_check).
 */
final readonly class SmokeCheckSettings
{
    /**
     * Most remote commands one step can run (path lookup, stats, the sibling
     * and the new region of the log, the lines before the baseline…), each
     * one with its own SSH command timeout.
     */
    public const int REMOTE_COMMANDS = 8;

    /**
     * @param  list<string>  $fatalPatterns  PCRE patterns; a new log line matching any is a fatal
     *
     * @throws InvalidArgumentException when a fatal pattern is not a valid regular expression
     */
    public function __construct(
        public int $stepTimeoutSeconds,
        public int $httpTimeoutSeconds,
        public int $maxRedirects,
        public bool $verifyTls,
        public int $maxCandidateLines,
        public int $signatureWindowBytes,
        public int $reportedLines,
        public int $lineMaxChars,
        public array $fatalPatterns,
    ) {
        if ($fatalPatterns === []) {
            throw new InvalidArgumentException('wpup.smoke_check.fatal_patterns no pot ser buit: cap fatal es detectaria.');
        }
        foreach ($fatalPatterns as $pattern) {
            // preg_match() returns false (and warns) on a broken pattern.
            if (@preg_match($pattern, '') === false) {
                throw new InvalidArgumentException("wpup.smoke_check.fatal_patterns conté una expressió regular no vàlida: {$pattern}");
            }
        }
    }

    /**
     * The longest the step can legitimately take: the SSH connection, every
     * remote command at its own timeout, and the home with all its redirects
     * (each hop with its own HTTP timeout).
     */
    public function worstCaseSeconds(int $sshConnectSeconds, int $sshCommandSeconds): int
    {
        return $sshConnectSeconds + self::REMOTE_COMMANDS * $sshCommandSeconds + ($this->maxRedirects + 1) * $this->httpTimeoutSeconds;
    }
}
