<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Secrets\SecretRedactor;

/**
 * Picks the fatal errors out of the new lines of the PHP error log
 * (UZEROWPUP-56). Pure classification, no I/O. Lines that are reported are
 * redacted first and then truncated, so a secret cut in half is never shown.
 *
 * A fatal is "new" unless its signature (the message with file and line, with
 * no timestamp or server prefix) was already in the log before the baseline:
 * a site that was already throwing the same fatal is not made worse by the
 * update, so that one is only a warning.
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
     * Stable identity of a fatal: its text without the leading [timestamp],
     * [pid]… groups, whitespace collapsed. Hashed, so the baseline kept in the
     * run never holds log text.
     */
    public function signature(string $line): string
    {
        $text = preg_replace('/^(?:\[[^\]]*\]\s*)+/', '', $line) ?? $line;

        return sha1(trim(preg_replace('/\s+/', ' ', $text) ?? $text));
    }

    /**
     * @param  list<string>  $lines
     * @return list<string> the distinct signatures of the fatals among them
     */
    public function signatures(array $lines): array
    {
        return array_values(array_unique(array_map($this->signature(...), array_filter($lines, $this->isFatal(...)))));
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $known  signatures present before the baseline
     * @return array{count: int, repeated: int, lines: list<string>} the new fatals, the ones that were already
     *                                                               there, and the first new ones (redacted, truncated) to show
     */
    public function fatals(array $lines, array $known = []): array
    {
        $new = [];
        $repeated = 0;
        foreach ($lines as $line) {
            if (! $this->isFatal($line)) {
                continue;
            }
            if (in_array($this->signature($line), $known, true)) {
                $repeated++;
            } else {
                $new[] = $line;
            }
        }
        $shown = array_map(
            fn (string $line): string => mb_strimwidth($this->redactor->redact($line), 0, $this->settings->lineMaxChars, '…'),
            array_slice($new, 0, $this->settings->reportedLines),
        );

        return ['count' => count($new), 'repeated' => $repeated, 'lines' => $shown];
    }
}
