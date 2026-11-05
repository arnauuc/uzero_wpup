<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Ssh;

use App\Domain\Drivers\Data\WorkFile;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Drivers\WorkPath;

/**
 * Parsers of what the SSH driver's file commands print, shared by every
 * method that reads them.
 */
final class FileOutput
{
    /** Printed instead of the stat when the file is missing (or not a regular file). */
    public const string MISSING = '__WPUP_MISSING__';

    /**
     * The command that prints, for the escaped absolute path $file, "size mtime"
     * and, when $withChecksum, the sha256sum line; or MISSING.
     */
    public static function statCommand(string $file, bool $withChecksum): string
    {
        return "if [ -f {$file} ] && [ ! -L {$file} ]; then stat -c '%s %Y' -- {$file}"
            .($withChecksum ? " && sha256sum -- {$file}" : '')
            .'; else echo '.self::MISSING.'; fi';
    }

    /**
     * @return array{int, int, string|null}|null size, mtime and SHA-256, or null if missing
     *
     * @throws RemoteCommandFailed when the output is not what statCommand() prints
     */
    public static function stat(string $out, bool $withChecksum, string $name): ?array
    {
        $out = trim($out);
        if ($out === self::MISSING) {
            return null;
        }

        $lines = explode("\n", $out);
        [$size, $mtime] = array_pad(explode(' ', $lines[0], 2), 2, '');
        $sha256 = $withChecksum ? substr($lines[1] ?? '', 0, 64) : null;
        if (! ctype_digit($size) || ! ctype_digit($mtime) || ($sha256 !== null && preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1)) {
            throw new RemoteCommandFailed("No s'ha pogut llegir la mida i el checksum de «{$name}».");
        }

        return [(int) $size, (int) $mtime, $sha256];
    }

    /**
     * The command that lists the regular files under the escaped directory $dir
     * as NUL-separated "path\0size\0mtime\0" records (GNU find): a name may
     * contain anything but NUL, newlines included. Symlinks are neither
     * followed nor listed.
     */
    public static function listCommand(string $dir): string
    {
        return "if [ -d {$dir} ] && [ ! -L {$dir} ]; then find -P {$dir} -type f -printf '%P\\0%s\\0%T@\\0'; fi";
    }

    /**
     * Parses listCommand() output. Names that are not WorkPaths (another
     * program's files) are left out.
     *
     * @param  string  $directory  WorkPath of the listed directory, '' for the work directory itself
     * @return list<WorkFile>
     */
    public static function listing(string $directory, string $out): array
    {
        $fields = explode("\0", $out);
        $files = [];
        for ($i = 0; $i + 2 < count($fields); $i += 3) {
            [$relative, $size, $mtime] = [$fields[$i], $fields[$i + 1], $fields[$i + 2]];
            $path = $directory === '' ? $relative : $directory.'/'.$relative;
            if ($relative !== '' && ctype_digit($size) && preg_match('/^\d+(\.\d+)?$/D', $mtime) === 1 && WorkPath::isValid($path)) {
                $files[] = new WorkFile($path, (int) $size, (int) $mtime);
            }
        }

        return $files;
    }
}
