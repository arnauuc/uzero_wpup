<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\Site;

/**
 * Tells the operators a site was blocked for updates, or released
 * (UZEROWPUP-34). The notification channels (UZEROWPUP F5) implement it;
 * until then LogSiteBlockNotifier only logs.
 */
interface SiteBlockNotifier
{
    public function siteBlocked(Site $site, string $reason): void;

    public function siteUnblocked(Site $site): void;
}
