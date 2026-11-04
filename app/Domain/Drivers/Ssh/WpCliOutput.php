<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Ssh;

use App\Domain\Drivers\Exceptions\RemoteCommandFailed;

/**
 * Reads what WP-CLI prints. Old sites send PHP notices to stdout, before (or
 * after) the real answer, so the JSON is looked for line by line.
 */
final class WpCliOutput
{
    /**
     * A JSON list of strings printed on a line of its own.
     *
     * @return list<string>
     *
     * @throws RemoteCommandFailed
     */
    public static function stringList(string $stdout): array
    {
        foreach (array_reverse(explode("\n", $stdout)) as $line) {
            $line = trim($line);
            if (! str_starts_with($line, '[')) {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return array_values(array_filter($decoded, is_string(...)));
            }
        }

        throw new RemoteCommandFailed('WP-CLI no ha tornat cap llista en JSON.');
    }
}
