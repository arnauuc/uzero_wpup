<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Enums\NotificationSeverity;
use App\Models\Run;
use App\Models\Site;

/**
 * A site stopped being updated automatically after consecutive failed
 * windows (ADR-0006 §3, UZEROWPUP-129): a warning.
 */
final class AutoUpdateBlockedNotification extends WpupNotification
{
    private readonly string $siteName;

    public function __construct(Site $site, Run $run, private readonly string $reason)
    {
        parent::__construct($site->id, $run->id);
        $this->siteName = $site->name;
    }

    public function kind(): NotificationKind
    {
        return NotificationKind::AutoUpdateBlocked;
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Warning;
    }

    public function subject(): string
    {
        return "Lloc {$this->siteName}: bloquejat per a l'actualització automàtica";
    }

    public function lines(): array
    {
        return [$this->reason, self::reportLine((int) $this->runId)];
    }
}
