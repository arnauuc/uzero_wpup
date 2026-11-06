<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Data;

/**
 * What SiteDriver::applyUpdate() did: the installer's raw output (redact it
 * before storing it) and the component as the site reports it afterwards.
 */
final readonly class AppliedUpdate
{
    public function __construct(
        public CommandResult $result,
        public ComponentVersion $installed,
        /** True when the target was already installed and nothing was uploaded. */
        public bool $alreadyInstalled,
    ) {}
}
