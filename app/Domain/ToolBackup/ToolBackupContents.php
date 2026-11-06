<?php

declare(strict_types=1);

namespace App\Domain\ToolBackup;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Which files of the tool server go into its backup besides the database
 * dump: the configured non-secret configuration and reports (ADR-0009 §3).
 *
 * Whatever the configuration lists, a file is never included when (AGENTS.md
 * rule 2):
 * - any segment of its path matches DENIED_NAMES (.env*, keys, secrets…);
 * - it is a symbolic link (it could point anywhere);
 * - its path is not valid UTF-8 (it could not be reported nor audited);
 * - its content looks like a secret (looksSecret(); in doubt, it does).
 * Each skipped path leaves a warning; missing and unreadable paths too.
 */
final readonly class ToolBackupContents
{
    /** fnmatch() patterns, case-insensitive, checked against every path segment. */
    public const array DENIED_NAMES = [
        '.env', '.env.*', '*.key', '*.pem', '*.p12', '*.pfx', '*.kdbx', 'id_*',
        '.ssh', '.gnupg', 'keys', '.keys', '*secret*', '*credential*', 'credstore*',
    ];

    /**
     * Content that never leaves the VM, even encrypted, outside the SecretStore.
     * Case-insensitive. The last one is a key/value pair (env, INI, YAML, JSON)
     * whose key names a secret and whose value is not empty nor null.
     */
    private const array CONTENT_MARKERS = [
        '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/i',
        '/AGE-SECRET-KEY-1/i',
        '/\btskey-[a-z]+-[a-z0-9]/i',
        '/["\']?[a-z0-9_.-]*(?:pass(?:word|wd)?|pwd|secret|token|api[_-]?key|auth[_-]?key|private)[a-z0-9_.-]*["\']?[ \t]*[:=][ \t]*["\']?(?!(?:null|none)\b|~)[^\s"\',}#]/i',
    ];

    private const int SCAN_CHUNK_BYTES = 1_048_576;

    private const int SCAN_OVERLAP_BYTES = 4096;

    /**
     * @param  list<string>  $paths  absolute files or directories
     * @return array{list<ToolBackupFile>, list<string>} files and warnings
     */
    public function collect(array $paths): array
    {
        $files = [];
        $warnings = [];

        foreach ($paths as $path) {
            if (! str_starts_with($path, '/')) {
                $warnings[] = 'S\'omet «'.self::printable($path).'»: el camí ha de ser absolut.';

                continue;
            }
            $path = rtrim($path, '/');
            if (! self::allowed($path, $warnings)) {
                continue;
            }
            if (is_file($path)) {
                $this->add($path, $files, $warnings);
            } elseif (is_dir($path)) {
                foreach ($this->walk($path, $warnings) as $file) {
                    $this->add($file, $files, $warnings);
                }
            } else {
                $warnings[] = "S'omet «{$path}»: no existeix.";
            }
        }

        return [$files, $warnings];
    }

    public static function isDenied(string $path): bool
    {
        foreach (explode('/', trim($path, '/')) as $segment) {
            foreach (self::DENIED_NAMES as $pattern) {
                if ($segment !== '' && fnmatch($pattern, $segment, FNM_CASEFOLD)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether $text looks like it holds a secret. Fails closed: a regex error
     * counts as a secret.
     */
    public static function looksLikeSecret(string $text): bool
    {
        foreach (self::CONTENT_MARKERS as $marker) {
            if (preg_match($marker, $text) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $warnings
     */
    private static function allowed(string $path, array &$warnings): bool
    {
        $reason = match (true) {
            ! mb_check_encoding($path, 'UTF-8') => 'el nom no és UTF-8 vàlid.',
            self::isDenied($path) => "pot contenir secrets i no s'inclou mai a la còpia.",
            is_link($path) => 'és un enllaç simbòlic.',
            is_dir($path) && (! is_readable($path) || ! is_executable($path)) => "no s'hi pot entrar.",
            default => null,
        };
        if ($reason !== null) {
            $warnings[] = 'S\'omet «'.self::printable($path)."»: {$reason}";
        }

        return $reason === null;
    }

    /**
     * Regular files under $dir, pruning (with a warning) what allowed() refuses.
     * A subdirectory that cannot be listed is skipped, never the whole walk.
     *
     * @param  list<string>  $warnings
     * @return list<string>
     */
    private function walk(string $dir, array &$warnings): array
    {
        $filter = static function (SplFileInfo $item) use (&$warnings): bool {
            return self::allowed($item->getPathname(), $warnings);
        };

        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
                $filter,
            ), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
            $found = [];
            foreach ($iterator as $item) {
                if ($item instanceof SplFileInfo && $item->isFile()) {
                    $found[] = $item->getPathname();
                }
            }
        } catch (UnexpectedValueException $e) {
            $warnings[] = "S'omet «{$dir}»: no s'ha pogut llegir ({$e->getMessage()}).";

            return [];
        }
        sort($found);

        return $found;
    }

    /**
     * @param  list<ToolBackupFile>  $files
     * @param  list<string>  $warnings
     */
    private function add(string $path, array &$files, array &$warnings): void
    {
        if (! is_readable($path)) {
            $warnings[] = "S'omet «{$path}»: no es pot llegir.";

            return;
        }
        if ($this->looksSecret($path)) {
            $warnings[] = "S'omet «{$path}»: sembla que conté un secret (clau privada, contrasenya o token).";

            return;
        }
        $files[] = new ToolBackupFile($path, 'files'.$path, (int) filesize($path));
    }

    private function looksSecret(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return true;
        }

        try {
            $tail = '';
            while (! feof($handle)) {
                $chunk = fread($handle, self::SCAN_CHUNK_BYTES);
                if ($chunk === false) {
                    return true;
                }
                $window = $tail.$chunk;
                if (self::looksLikeSecret($window)) {
                    return true;
                }
                $tail = substr($window, -self::SCAN_OVERLAP_BYTES);
            }
        } finally {
            fclose($handle);
        }

        return false;
    }

    private static function printable(string $path): string
    {
        return mb_scrub($path, 'UTF-8');
    }
}
