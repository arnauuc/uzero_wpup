<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Secrets\SecretRedactor;
use App\Enums\PhpLogLevel;

/**
 * Classifies the lines of the PHP error log (UZEROWPUP-56, 71). Pure
 * classification, no I/O. Lines that are reported are redacted first and then
 * truncated, so a secret cut in half is never shown.
 *
 * A fatal (or warning) is "new" unless its signature (the message with file and
 * line, with no timestamp or server prefix) was already in the log before the
 * baseline: a site that was already throwing the same fatal is not made worse by
 * the update, so that one is only a warning (a repeated warning is only counted).
 *
 * A proxy_fcgi line can hold several messages ("PHP message: …PHP message: …");
 * they are split before classifying, so a fatal after a warning is not hidden.
 */
final readonly class PhpLogScanner
{
    /** The leading [timestamp], [pid]… groups of a log line. */
    private const string PREFIX = '/^(?:\[[^\]]*\]\s*)+/';

    /** Longest timestamp kept in a group. */
    private const int TIMESTAMP_MAX_CHARS = 40;

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
     * A line with several "PHP message:" parts becomes one line per part, each
     * with the line's own prefix; any other line stays as it is.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function expand(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $pieces = preg_split('/(?=PHP message:)/', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [$line];
            $prefix = str_starts_with($line, 'PHP message:') ? '' : array_shift($pieces);
            if (count($pieces) < 2) {
                $out[] = $line;

                continue;
            }
            foreach ($pieces as $piece) {
                $out[] = $prefix.$piece;
            }
        }

        return $out;
    }

    /**
     * Stable identity of a line: its text without the leading [timestamp],
     * [pid]… groups, whitespace collapsed. Hashed, so the baseline kept in the
     * run never holds log text.
     */
    public function signature(string $line): string
    {
        $text = preg_replace(self::PREFIX, '', $line) ?? $line;

        return sha1(trim(preg_replace('/\s+/', ' ', $text) ?? $text));
    }

    /**
     * @param  list<string>  $lines
     * @return list<string> the distinct signatures of the fatals among them
     */
    public function signatures(array $lines): array
    {
        return $this->signaturesOf($lines, PhpLogLevel::Fatal);
    }

    /**
     * @param  list<string>  $lines
     * @return list<string> the distinct signatures of the warnings among them
     */
    public function warningSignatures(array $lines): array
    {
        return $this->signaturesOf($lines, PhpLogLevel::Warning);
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function signaturesOf(array $lines, PhpLogLevel $level): array
    {
        $found = array_filter($this->expand($lines), fn (string $line): bool => $this->level($line) === $level);

        return array_values(array_unique(array_map($this->signature(...), $found)));
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $known  signatures present before the baseline
     * @return array{count: int, repeated: int, lines: list<string>, groups: list<LogIssueGroup>} the new fatals, the ones that were
     *                                                                                            already there, the first new ones (redacted, truncated) to show, and the new ones grouped
     */
    public function fatals(array $lines, array $known = []): array
    {
        [$new, $repeated] = $this->split($lines, PhpLogLevel::Fatal, $known);
        $shown = array_map(
            fn (string $line): string => mb_strimwidth($this->redactor->redact($line), 0, $this->settings->lineMaxChars, '…'),
            array_slice($new, 0, $this->settings->reportedLines),
        );

        return ['count' => count($new), 'repeated' => $repeated, 'lines' => $shown, 'groups' => array_slice($this->groupAll($new, PhpLogLevel::Fatal), 0, $this->settings->maxGroups)];
    }

    /**
     * The new warnings among the lines (any line may hold one, also a fatal
     * line with a warning part), grouped; the ones whose signature was already
     * in the log before the baseline are only counted.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $known  warning signatures present before the baseline
     * @return array{warnings: int, repeated: int, distinct: int, groups: list<LogIssueGroup>} distinct is the number of different new
     *                                                                                         messages; groups holds at most max_groups of them
     */
    public function warnings(array $lines, array $known = []): array
    {
        [$new, $repeated] = $this->split($lines, PhpLogLevel::Warning, $known);
        $groups = $this->groupAll($new, PhpLogLevel::Warning);

        return ['warnings' => count($new), 'repeated' => $repeated, 'distinct' => count($groups), 'groups' => array_slice($groups, 0, $this->settings->maxGroups)];
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $known
     * @return array{list<string>, int} the lines of $level whose signature is not known, and how many were
     */
    private function split(array $lines, PhpLogLevel $level, array $known): array
    {
        $new = [];
        $repeated = 0;
        foreach ($this->expand($lines) as $line) {
            if ($this->level($line) !== $level) {
                continue;
            }
            if (in_array($this->signature($line), $known, true)) {
                $repeated++;
            } else {
                $new[] = $line;
            }
        }

        return [$new, $repeated];
    }

    /**
     * Repeated lines folded by signature (same message, file and line): count
     * and first/last time seen (by the log's own timestamps, not by the order
     * the lines came in), in order of first appearance.
     *
     * @param  list<string>  $lines
     * @return list<LogIssueGroup>
     */
    private function groupAll(array $lines, PhpLogLevel $level): array
    {
        $acc = [];
        foreach ($lines as $line) {
            $signature = $this->signature($line);
            $at = preg_match('/^\[([^\]]*)\]/', $line, $m) === 1 ? $m[1] : null;
            $ts = $at === null ? null : (strtotime($at) ?: null);
            $acc[$signature] ??= ['message' => $this->message($line), 'count' => 0, 'first' => null, 'last' => null];
            $acc[$signature]['count']++;
            if ($at !== null) {
                if ($acc[$signature]['first'] === null || ($ts !== null && $acc[$signature]['first'][0] !== null && $ts < $acc[$signature]['first'][0])) {
                    $acc[$signature]['first'] = [$ts, $at];
                }
                if ($acc[$signature]['last'] === null || $acc[$signature]['last'][0] === null || ($ts !== null && $ts >= $acc[$signature]['last'][0])) {
                    $acc[$signature]['last'] = [$ts, $at];
                }
            }
        }

        $groups = [];
        foreach ($acc as $signature => $g) {
            $groups[] = new LogIssueGroup((string) $signature, $level, $g['message'], $g['count'], $this->clean($g['first'][1] ?? null), $this->clean($g['last'][1] ?? null));
        }

        return $groups;
    }

    private function clean(?string $value): ?string
    {
        return $value === null ? null : mb_strimwidth($this->redactor->redact($value), 0, self::TIMESTAMP_MAX_CHARS, '…');
    }

    private function message(string $line): string
    {
        $text = trim(preg_replace(self::PREFIX, '', $line) ?? $line);

        return mb_strimwidth($this->redactor->redact($text), 0, $this->settings->lineMaxChars, '…');
    }
}
