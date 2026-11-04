<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\Site;
use Illuminate\Support\Facades\Log;

/**
 * Default notifier until the real channels exist: writes to the log.
 */
final class LogSiteBlockNotifier implements SiteBlockNotifier
{
    public function siteBlocked(Site $site, string $reason): void
    {
        Log::warning("Site {$site->name} blocked for updates: {$reason}", ['site_id' => $site->id]);
    }

    public function siteUnblocked(Site $site): void
    {
        Log::info("Site {$site->name} unblocked for updates", ['site_id' => $site->id]);
    }
}
