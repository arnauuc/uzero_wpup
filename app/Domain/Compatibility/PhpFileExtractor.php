<?php

declare(strict_types=1);

namespace App\Domain\Compatibility;

use ZipArchive;

/**
 * Extracts only the *.php files of a package ZIP into a directory we created.
 * Entries are never handed to ZipArchive::extractTo(): each name is validated
 * (no absolute path, no "..", no backslash, no NUL, no drive letter) and each
 * file is created exclusively (never over an existing file) by copying the
 * entry's stream, so a symlink entry becomes a plain file and nothing can
 * write outside the target (zip-slip). Count and size are bounded.
 */
final class PhpFileExtractor
{
    /**
     * @return int number of files extracted
     *
     * @throws StaticAnalysisFailed
     */
    public function extract(string $zipPath, string $target, int $maxFiles, int $maxUnpackedBytes): int
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw StaticAnalysisFailed::extraction('no és un ZIP vàlid.');
        }

        try {
            $files = 0;
            $bytes = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || str_ends_with($name, '/') || ! self::isPhpFile($name)) {
                    continue;
                }
                if (! self::isSafe($name)) {
                    throw StaticAnalysisFailed::extraction('el ZIP conté una ruta no permesa.');
                }
                if (++$files > $maxFiles) {
                    throw StaticAnalysisFailed::extraction("el ZIP té massa fitxers PHP (màxim {$maxFiles}).");
                }
                $bytes += $this->copy($zip, $i, $target.'/'.$name, $maxUnpackedBytes - $bytes);
            }

            return $files;
        } finally {
            $zip->close();
        }
    }

    public static function isPhpFile(string $name): bool
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'php';
    }

    public static function isSafe(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) {
            return false;
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '..' || $segment === '.' || $segment === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return int bytes written
     *
     * @throws StaticAnalysisFailed
     */
    private function copy(ZipArchive $zip, int $index, string $path, int $remaining): int
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw StaticAnalysisFailed::extraction('no es pot crear un directori.');
        }
        $in = $zip->getStream((string) $zip->getNameIndex($index));
        $out = $in === false ? false : @fopen($path, 'xb');
        if ($in === false || $out === false) {
            throw StaticAnalysisFailed::extraction('no es pot escriure un fitxer (o el ZIP repeteix una ruta).');
        }

        try {
            $written = 0;
            while (! feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false) {
                    throw StaticAnalysisFailed::extraction('error de lectura del ZIP.');
                }
                $written += strlen($chunk);
                if ($written > $remaining) {
                    throw StaticAnalysisFailed::extraction('el contingut descomprimit supera el límit.');
                }
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw StaticAnalysisFailed::extraction('no es pot escriure un fitxer.');
                }
            }

            return $written;
        } finally {
            fclose($in);
            fclose($out);
        }
    }
}
