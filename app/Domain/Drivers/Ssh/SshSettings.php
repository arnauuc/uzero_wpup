<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Ssh;

final readonly class SshSettings
{
    public function __construct(
        public int $connectTimeoutSeconds,
        public int $commandTimeoutSeconds,
        public int $transferTimeoutSeconds,
        public string $workDir,
        public string $wpCliPhar,
        public string $wpCliSha256,
        // Private directory of the tool server for temporary copies (never /tmp).
        public string $stagingDir,
        // Each of the upload, the on-site checksum and the install of applyUpdate().
        public int $packageTimeoutSeconds = 600,
    ) {}

    /**
     * The longest applyUpdate() can take: the connection, three package
     * operations (upload, checksum, install) and six plain commands (two
     * reads, the cleanups, the move, the WP-CLI check). An update step must
     * allow at least this, so its timeout never fires mid-install.
     */
    public function applyUpdateBudgetSeconds(): int
    {
        return $this->connectTimeoutSeconds + 3 * $this->packageTimeoutSeconds + 6 * $this->commandTimeoutSeconds;
    }
}
