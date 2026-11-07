<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Secrets\SecretRedactor;
use App\Enums\PhpLogLevel;

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
        return $this->matches($this->settings->fatalPatterns, $line);
    }

    /** Fatal, warning or notice (notices include deprecations); null for anything else in the log. */
    public function level(string $line): ?PhpLogLevel
    {
        return match (true) {
            $this->isFatal($line) => PhpLogLevel::Fatal,
            $this->matches($this->settings->warningPatterns, $line) => PhpLogLevel::Warning,
            $this->matches($this->settings->noticePatterns, $line) => PhpLogLevel::Notice,
            default => null,
        };
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matches(array $patterns, string $line): bool
    {
        foreach ($patterns as $pattern) {
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
     * @return array{count: int, repeated: int, lines: list<string>, groups: list<LogIssueGroup>} the new fatals, the ones that were
     *                                                                                            already there, the first new ones (redacted, truncated) to show, and the new ones grouped
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

        return ['count' => count($new), 'repeated' => $repeated, 'lines' => $shown, 'groups' => $this->group($new, PhpLogLevel::Fatal)];
    }

    /**
     * New warnings (grouped, worth reporting) and notices (only counted) among
     * the lines; anything else is ignored.
     *
     * @param  list<string>  $lines
     * @return array{warnings: int, notices: int, groups: list<LogIssueGroup>}
     */
    public function warnings(array $lines): array
    {
        $warnings = [];
        $notices = 0;
        foreach ($lines as $line) {
            $level = $this->level($line);
            if ($level === PhpLogLevel::Warning) {
                $warnings[] = $line;
            } elseif ($level === PhpLogLevel::Notice) {
                $notices++;
            }
        }

        return ['warnings' => count($warnings), 'notices' => $notices, 'groups' => $this->group($warnings, PhpLogLevel::Warning)];
    }

    /**
     * Repeated lines folded by signature (same message, file and line): count
     * and first/last time seen, in order of first appearance, at most max_groups.
     *
     * @param  list<string>  $lines
     * @return list<LogIssueGroup>
     */
    public function group(array $lines, PhpLogLevel $level): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $signature = $this->signature($line);
            $at = preg_match('/^\[([^\]]*)\]/', $line, $m) === 1 ? $m[1] : null;
            $groups[$signature] = isset($groups[$signature])
                ? $groups[$signature]->seenAgain($at)
                : new LogIssueGroup($signature, $level, $this->message($line), 1, $at, $at);
        }

        return array_slice(array_values($groups), 0, $this->settings->maxGroups);
    }

    private function message(string $line): string
    {
        $text = trim(preg_replace('/^(?:\[[^\]]*\]\s*)+/', '', $line) ?? $line);

        return mb_strimwidth($this->redactor->redact($text), 0, $this->settings->lineMaxChars, '…');
    }
}
