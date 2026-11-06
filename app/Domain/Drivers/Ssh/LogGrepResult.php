<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Ssh;

use App\Domain\Drivers\Exceptions\RemoteCommandFailed;

/**
 * Result of the remote `tail | grep | cut` that reads a PHP log (UZEROWPUP-56).
 * A pipeline only reports its last command, and POSIX sh has no pipefail, so
 * the command prints the exit status of tail and of grep as marker lines on
 * stderr. Without them verified, an unreadable log would look like a log with
 * no fatal lines: a silent false pass on the rollback path.
 *
 * tail 141 is a SIGPIPE because grep (or head) stopped reading early: not an
 * error. grep 1 means "no match": not an error either.
 */
final readonly class LogGrepResult
{
    /**
     * @param  list<string>  $lines
     */
    private function __construct(public array $lines) {}

    /**
     * The shell wrapper: runs $reader (a command that prints the log bytes) and
     * pipes it (optionally cut to $length bytes; $newest keeps the LAST $maxLines matches instead of the first) through grep with the markers on
     * stderr. Arguments must be escaped.
     */
    public static function command(string $reader, string $pattern, int $maxLines, int $lineBytes, ?int $length = null, bool $newest = false): string
    {
        return '{ '.$reader.'; echo "wpup-tail=$?" >&2; }'
            .($length === null ? '' : ' | head -c '.max(1, $length))
            // Oldest first and capped (the new region), or all of them and then only the newest $maxLines
            // (the region before the baseline: the most recent fatals are the ones that matter).
            .' | { grep -a -E '.($newest ? '' : '-m '.max(1, $maxLines).' ').escapeshellarg($pattern).'; echo "wpup-grep=$?" >&2; }'
            .($newest ? ' | tail -n '.max(1, $maxLines) : '')
            .' | cut -c1-'.$lineBytes;
    }

    /**
     * @throws RemoteCommandFailed when the markers are missing or say tail or grep failed
     */
    public static function parse(string $stdout, string $stderr): self
    {
        $tail = self::status($stderr, 'tail');
        $grep = self::status($stderr, 'grep');
        if (($tail !== 0 && $tail !== 141) || ($grep !== 0 && $grep !== 1)) {
            $reason = $tail === null || $grep === null ? 'sense resultat verificable' : 'tail='.$tail.', grep='.$grep;

            throw new RemoteCommandFailed("La lectura del log de PHP ha fallat ({$reason}).");
        }

        return new self(array_values(array_filter(explode("\n", $stdout), static fn (string $line): bool => $line !== '')));
    }

    private static function status(string $stderr, string $which): ?int
    {
        return preg_match('/^wpup-'.$which.'=(\d+)$/m', $stderr, $m) === 1 ? (int) $m[1] : null;
    }
}
