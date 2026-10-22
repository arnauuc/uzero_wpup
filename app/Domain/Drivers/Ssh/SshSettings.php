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
    ) {}
}
