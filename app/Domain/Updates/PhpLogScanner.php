<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Secrets\SecretRedactor;

/**
 * Picks the fatal errors out of the new lines of the PHP error log
 * (UZEROWPUP-56). Pure classification, no I/O. Lines that are reported are
 * redacted first and then truncated, so a secret cut in half is never shown.
 */
final readonly class PhpLogScanner
{
    public function __construct(
        private SecretRedactor $redactor,
        private SmokeCheckSettings $settings,
    ) {}

    public function isFatal(string $line): bool
    {
        foreach ($this->settings->fatalPatterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $lines
     * @return array{count: int, lines: list<string>} every fatal found, and the first ones (redacted, truncated) to show
     */
    public function fatals(array $lines): array
    {
        $found = array_values(array_filter($lines, $this->isFatal(...)));
        $shown = array_map(
            fn (string $line): string => mb_strimwidth($this->redactor->redact($line), 0, $this->settings->lineMaxChars, '…'),
            array_slice($found, 0, $this->settings->reportedLines),
        );

        return ['count' => count($found), 'lines' => $shown];
    }
}
