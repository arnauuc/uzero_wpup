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
    public const int REMOTE_COMMANDS = 16;

    /**
     * @param  list<string>  $fatalPatterns  PCRE patterns; a new log line matching any is a fatal
     * @param  list<string>  $warningPatterns  same for warnings (a new one only warns, UZEROWPUP-71)
     * @param  list<string>  $noticePatterns  same for notices and deprecations (only counted)
     *
     * @throws InvalidArgumentException when a fatal pattern is not a valid regular expression
     */
    public function __construct(
        public int $stepTimeoutSeconds,
        public int $httpTimeoutSeconds,
        public int $maxRedirects,
        public int $httpAttempts,
        public int $httpAttemptPauseSeconds,
        public bool $verifyTls,
        public int $maxCandidateLines,
        public int $signatureWindowBytes,
        public int $reportedLines,
        public int $lineMaxChars,
        public array $fatalPatterns,
        public array $warningPatterns,
        public array $noticePatterns,
        public int $maxGroups,
    ) {
        if ($httpAttempts < 1 || $httpAttemptPauseSeconds < 0) {
            throw new InvalidArgumentException('wpup.smoke_check.http_attempts ha de ser com a mínim 1 i http_attempt_pause_seconds no pot ser negatiu.');
        }
        if ($fatalPatterns === []) {
            throw new InvalidArgumentException('wpup.smoke_check.fatal_patterns no pot ser buit: cap fatal es detectaria.');
        }
        if ($warningPatterns === [] || $noticePatterns === [] || $maxGroups < 1) {
            throw new InvalidArgumentException('wpup.smoke_check.warning_patterns i notice_patterns no poden ser buits i max_groups ha de ser com a mínim 1.');
        }
        foreach (['fatal_patterns' => $fatalPatterns, 'warning_patterns' => $warningPatterns, 'notice_patterns' => $noticePatterns] as $key => $patterns) {
            foreach ($patterns as $pattern) {
                // preg_match() returns false (and warns) on a broken pattern.
                if (@preg_match($pattern, '') === false) {
                    throw new InvalidArgumentException("wpup.smoke_check.{$key} conté una expressió regular no vàlida: {$pattern}");
                }
            }
        }
    }

    /**
     * The longest the step can legitimately take: the SSH connection, every
     * remote command at its own timeout, and every request of the home with all
     * its redirects (each hop with its own HTTP timeout) plus the pauses between them.
     */
    public function worstCaseSeconds(int $sshConnectSeconds, int $sshCommandSeconds): int
    {
        return $sshConnectSeconds + self::REMOTE_COMMANDS * $sshCommandSeconds
            + $this->httpAttempts * ($this->maxRedirects + 1) * $this->httpTimeoutSeconds
            + ($this->httpAttempts - 1) * $this->httpAttemptPauseSeconds;
    }
}
