<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Ssh;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Secrets\SecretStore;
use App\Enums\SiteDriverType;
use App\Models\Site;

/**
 * Builds the SSH driver of a site. The account's key is revealed once (and
 * audited) and only lives in memory in the driver.
 */
final readonly class SshDriverFactory
{
    public function __construct(
        private SecretStore $secrets,
        private SshSettings $settings,
    ) {}

    public function forSite(Site $site, AuditActor $actor): SshDriver
    {
        $account = $site->hostingAccount ?? throw new DriverException("El lloc {$site->name} no té compte d'hosting.");
        $server = $account->hostingServer ?? throw new DriverException("El compte {$account->system_user} no té servidor.");

        if ($account->driver !== SiteDriverType::Ssh) {
            throw new DriverException("El compte {$account->system_user} no fa servir el driver SSH.");
        }

        $ref = $account->credential_ref
            ?? throw new ConnectionFailed("El compte {$account->system_user} no té cap clau SSH: genera-la amb ssh-key:generate.");
        $key = $this->secrets->reveal($ref, $actor, "ssh:{$site->name}");

        $target = new SshTarget($server->hostname, $server->ssh_port, $account->system_user, $server->pinned_host_key, $site->wp_path, $site->php_binary);

        return new SshDriver(
            $target,
            new SshConnection($target, $key, $this->settings->connectTimeoutSeconds),
            new RsyncTransfer($target, $key, $this->settings->transferTimeoutSeconds),
            $this->settings,
        );
    }
}
