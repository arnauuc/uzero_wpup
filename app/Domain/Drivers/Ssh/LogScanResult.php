<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Ssh;

use App\Domain\Drivers\Data\LogScan;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;

/**
 * The remote `tail | awk` that reads a region of a PHP log once and sorts its
 * lines into fatals, warnings and (only counted) notices, each with its own cap
 * (UZEROWPUP-56, 71). A pipeline only reports its last command, and POSIX sh has
 * no pipefail, so the command prints the exit status of tail and of awk as
 * marker lines on stderr. Without them verified, an unreadable log would look
 * like a log with nothing in it: a silent false pass on the rollback path.
 *
 * tail 141 is a SIGPIPE because head (the byte window) stopped reading: not an error.
 */
final readonly class LogScanResult
{
    /**
     * Fatal first, then warning, then notice: a line that has several
     * (a proxy_fcgi line with many "PHP message:") counts for the worst one.
     * With $newest the LAST matches are kept (circular buffer), else the first.
     */
    private const string AWK = 'BEGIN { nf = 0; nw = 0; nn = 0 } /PHP (Fatal error|Parse error):/ { if (newest) fa[nf % mf] = substr($0, 1, lb); else if (nf < mf) fa[nf] = substr($0, 1, lb); nf++; next }'
        .' /PHP Warning:/ { if (newest) wa[nw % mw] = substr($0, 1, lb); else if (nw < mw) wa[nw] = substr($0, 1, lb); nw++; next }'
        .' /PHP (Notice|Deprecated|Strict Standards):/ { nn++; next }'
        .' END {'
        .' s = (newest && nf > mf) ? nf - mf : 0; e = (newest || nf <= mf) ? nf : mf; for (i = s; i < e; i++) print "F:" fa[i % mf];'
        .' s = (newest && nw > mw) ? nw - mw : 0; e = (newest || nw <= mw) ? nw : mw; for (i = s; i < e; i++) print "W:" wa[i % mw];'
        .' print "#F:" nf + 0; print "#W:" nw + 0; print "#N:" nn + 0 }';

    /**
     * The shell wrapper: runs $reader (a command that prints the log bytes),
     * optionally cut to $length bytes, through awk with the markers on stderr.
     * Arguments must be escaped.
     */
    public static function command(string $reader, int $maxFatals, int $maxWarnings, int $lineBytes, ?int $length = null, bool $newest = false): string
    {
        return '{ '.$reader.'; echo "wpup-tail=$?" >&2; }'
            .($length === null ? '' : ' | head -c '.max(1, $length))
            .' | { LC_ALL=C awk -v mf='.max(1, $maxFatals).' -v mw='.max(1, $maxWarnings).' -v lb='.max(1, $lineBytes).' -v newest='.($newest ? 1 : 0)
            .' '.escapeshellarg(self::AWK).'; echo "wpup-awk=$?" >&2; }';
    }

    /**
     * @throws RemoteCommandFailed when the markers are missing or say tail or awk failed, or the totals are missing
     */
    public static function parse(string $stdout, string $stderr): LogScan
    {
        $tail = self::status($stderr, 'tail');
        $awk = self::status($stderr, 'awk');
        if (($tail !== 0 && $tail !== 141) || $awk !== 0) {
            $reason = $tail === null || $awk === null ? 'sense resultat verificable' : 'tail='.$tail.', awk='.$awk;

            throw new RemoteCommandFailed("La lectura del log de PHP ha fallat ({$reason}).");
        }

        $fatals = [];
        $warnings = [];
        $totals = [];
        foreach (explode("\n", $stdout) as $line) {
            if (str_starts_with($line, 'F:') && $line !== 'F:') {
                $fatals[] = substr($line, 2);
            } elseif (str_starts_with($line, 'W:') && $line !== 'W:') {
                $warnings[] = substr($line, 2);
            } elseif (preg_match('/^#([FWN]):(\d+)$/', $line, $m) === 1) {
                $totals[$m[1]] = (int) $m[2];
            }
        }
        if (! isset($totals['F'], $totals['W'], $totals['N'])) {
            throw new RemoteCommandFailed('La lectura del log de PHP ha fallat (sense els totals).');
        }

        return new LogScan(
            lines: $fatals,
            capped: $totals['F'] > count($fatals),
            warnings: $warnings,
            notices: $totals['N'],
            warningsCapped: $totals['W'] > count($warnings),
        );
    }

    private static function status(string $stderr, string $which): ?int
    {
        return preg_match('/^wpup-'.$which.'=(\d+)$/m', $stderr, $m) === 1 ? (int) $m[1] : null;
    }
}
