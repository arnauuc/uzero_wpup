<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Support\PrivateDirectory;
use RuntimeException;
use Throwable;

/**
 * Private temporary files of the tool server's staging directory (0700
 * directory, 0600 files; never /tmp) for objects on their way between a
 * destination and a site: SiteLocalTarget::put() and restores from an
 * external destination (SiteRestore).
 */
final class StagingCopy
{
    /** fread() calls in a row that may return nothing before a stream counts as stuck. */
    private const int MAX_IDLE_READS = 1000;

    /**
     * A new, unused path of the staging directory.
     *
     * @param  string  $what  operation, for the message ("L'escriptura")
     *
     * @throws BackupTargetException
     */
    public static function path(string $stagingDir, string $prefix, string $what, string $key): string
    {
        try {
            return PrivateDirectory::ensure($stagingDir).'/'.$prefix.'-'.bin2hex(random_bytes(16));
        } catch (RuntimeException $e) {
            throw BackupTargetException::failed($what, $key, $e->getMessage());
        }
    }

    /**
     * The stream (rewound when seekable) copied in chunks to a new private
     * file, and its SHA-256. The file is always closed; on any failure it is
     * removed. A stream that stops giving bytes before its end, or that ends
     * before $expectedBytes, is a failure.
     *
     * @param  resource  $stream
     * @return array{string, string} path and SHA-256
     *
     * @throws BackupTargetException
     */
    public static function copy($stream, string $stagingDir, int $chunkBytes, string $what, string $key, ?int $expectedBytes = null): array
    {
        $path = self::path($stagingDir, 'copy', $what, $key);
        $out = @fopen($path, 'xb');
        if ($out === false) {
            throw BackupTargetException::failed($what, $key, "no s'ha pogut crear el fitxer temporal");
        }
        $hash = hash_init('sha256');
        $bytes = 0;

        try {
            chmod($path, 0600);
            if (stream_get_meta_data($stream)['seekable']) {
                rewind($stream);
            }
            $idle = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, max(1, $chunkBytes));
                if ($chunk === false || fwrite($out, $chunk) !== strlen($chunk)) {
                    throw BackupTargetException::failed($what, $key, 'còpia temporal interrompuda');
                }
                $idle = $chunk === '' ? $idle + 1 : 0;
                // A read that times out returns nothing: a stuck stream ends here.
                if ($idle > self::MAX_IDLE_READS) {
                    throw BackupTargetException::failed($what, $key, 'el flux ha deixat de donar dades');
                }
                hash_update($hash, $chunk);
                $bytes += strlen($chunk);
            }
            if ($expectedBytes !== null && $bytes !== $expectedBytes) {
                throw BackupTargetException::failed($what, $key, "lectura incompleta ({$bytes} de {$expectedBytes} bytes)");
            }
        } catch (Throwable $e) {
            fclose($out);
            @unlink($path);

            throw $e;
        }
        fclose($out);

        return [$path, hash_final($hash)];
    }
}
