<?php

declare(strict_types=1);

namespace App\Domain\Drivers;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\Ssh\SshDriverFactory;
use App\Enums\SiteDriverType;
use App\Models\Site;

/**
 * Picks the SiteDriver of a site from its hosting account. Domain code asks
 * here and never knows which driver it got (AGENTS.md).
 */
final readonly class DriverFactory
{
    public function __construct(private SshDriverFactory $ssh) {}

    /**
     * @throws DriverException
     */
    public function forSite(Site $site, AuditActor $actor): SiteDriver
    {
        return match ($site->hostingAccount()->firstOrFail()->driver) {
            SiteDriverType::Ssh => $this->ssh->forSite($site, $actor),
            SiteDriverType::Agent => throw new DriverException("El driver d'agent encara no està disponible (F4); el lloc {$site->name} no es pot fer servir."),
        };
    }
}
