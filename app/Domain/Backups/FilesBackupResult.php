<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Drivers\Data\RemoteFile;

/**
 * A code archive on the site's server.
 */
final readonly class FilesBackupResult
{
    /**
     * @param  list<string>  $excludes  paths left out, relative to the WordPress root
     * @param  list<string>  $warnings  operator-readable notes (Catalan)
     */
    public function __construct(
        public RemoteFile $file,
        public array $excludes,
        public array $warnings = [],
    ) {}
}
