<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Drivers\Data\RemoteFile;

/**
 * A validated database dump on the site's server.
 */
final readonly class DatabaseBackupResult
{
    /**
     * @param  list<string>  $tables  the tables the dump was validated to contain
     * @param  OrderWatermark|null  $watermark  read right before the dump; null if it could not be
     * @param  list<string>  $warnings
     */
    public function __construct(
        public RemoteFile $file,
        public array $tables,
        public ?OrderWatermark $watermark = null,
        public array $warnings = [],
    ) {}
}
