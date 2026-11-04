<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use UnexpectedValueException;

/**
 * Reads the version of a `--json` document from the config, strictly: a bad value
 * must fail loudly instead of publishing a document nobody can parse.
 */
final class ReportVersion
{
    public static function read(string $configKey): int
    {
        $version = config($configKey);

        return is_int($version) ? $version : throw new UnexpectedValueException("{$configKey} must be an integer.");
    }
}
