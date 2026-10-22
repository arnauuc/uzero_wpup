<?php

declare(strict_types=1);

namespace App\Domain\Sites;

use App\Enums\HostingPanel;
use App\Enums\SiteDriverType;

/**
 * Where a new site lives: an existing hosting server and account, or new ones
 * (the hostname is then required). Credentials are managed elsewhere.
 */
final readonly class HostingPlacement
{
    public function __construct(
        public string $server,
        public string $account,
        public ?string $hostname = null,
        public ?int $sshPort = null,
        public ?HostingPanel $panel = null,
        public ?SiteDriverType $driver = null,
    ) {}
}
