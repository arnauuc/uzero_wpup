<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Enums\NotificationSeverity;
use App\Models\Site;

/**
 * The prerequisites blocked a site for updates (UZEROWPUP-34), or lifted the
 * block: a warning, or information when it is released.
 */
final class SiteBlockedNotification extends WpupNotification
{
    private readonly string $siteName;

    /**
     * @param  string|null  $reason  null when the block is lifted
     */
    public function __construct(Site $site, public readonly ?string $reason)
    {
        parent::__construct($site->id);
        $this->siteName = $site->name;
    }

    public function kind(): NotificationKind
    {
        return $this->reason === null ? NotificationKind::SiteUnblocked : NotificationKind::SiteBlocked;
    }

    public function severity(): NotificationSeverity
    {
        return $this->reason === null ? NotificationSeverity::Info : NotificationSeverity::Warning;
    }

    public function subject(): string
    {
        return $this->reason === null
            ? "Lloc {$this->siteName}: desbloquejat per a les actualitzacions"
            : "Lloc {$this->siteName}: bloquejat per a les actualitzacions";
    }

    public function lines(): array
    {
        return $this->reason === null ? ['Tots els prerequisits es tornen a complir.'] : ["Motiu: {$this->reason}"];
    }
}
