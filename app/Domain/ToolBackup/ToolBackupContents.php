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
 * - its content looks like a secret (CONTENT_MARKERS).
 * Each skipped path leaves a warning; missing paths too.
 */
final readonly class ToolBackupContents
{
    /** fnmatch() patterns, case-insensitive, checked against every path segment. */
    public const array DENIED_NAMES = [
        '.env', '.env.*', '*.key', '*.pem', '*.p12', '*.pfx', '*.kdbx', 'id_*',
        '.ssh', '.gnupg', 'keys', '.keys', '*secret*', '*credential*', 'credstore*',
    ];

    /** Content that never leaves the VM, even encrypted, outside the SecretStore. */
    private const array CONTENT_MARKERS = [
        '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/',
        '/AGE-SECRET-KEY-1/',
        '/^[ \t]*(?:export[ \t]+)?[A-Z0-9_]*(?:PASSWORD|PASSWD|SECRET|TOKEN|API_KEY|APP_KEY|PRIVATE_KEY|KEK)[ \t]*=[ \t]*["\']?(?!null\b)[^\s"\'#]/m',
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
                $warnings[] = "S'omet «{$path}»: el camí ha de ser absolut.";

                continue;
            }
            $path = rtrim($path, '/');
            if (self::isDenied($path)) {
                $warnings[] = "S'omet «{$path}»: pot contenir secrets i no s'inclou mai a la còpia.";

                continue;
            }
            if (is_link($path)) {
                $warnings[] = "S'omet «{$path}»: és un enllaç simbòlic.";

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
     * Regular files under $dir, pruning denied names and symbolic links.
     *
     * @param  list<string>  $warnings
     * @return list<string>
     */
    private function walk(string $dir, array &$warnings): array
    {
        $filter = static function (SplFileInfo $item) use (&$warnings): bool {
            $path = $item->getPathname();
            if ($item->isLink() || self::isDenied($path)) {
                $warnings[] = "S'omet «{$path}»: ".($item->isLink() ? 'és un enllaç simbòlic.' : 'pot contenir secrets.');

                return false;
            }

            return true;
        };

        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
                $filter,
            ));
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
                foreach (self::CONTENT_MARKERS as $marker) {
                    if (preg_match($marker, $window) === 1) {
                        return true;
                    }
                }
                $tail = substr($window, -self::SCAN_OVERLAP_BYTES);
            }
        } finally {
            fclose($handle);
        }

        return false;
    }
}
