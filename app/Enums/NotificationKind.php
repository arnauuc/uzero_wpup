<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a notification is about (UZEROWPUP-79).
 */
enum NotificationKind: string
{
    case RunFinished = 'run_finished';
    case RunRolledBack = 'run_rolled_back';
    case RunInterrupted = 'run_interrupted';
    case SiteBlocked = 'site_blocked';
    case SiteUnblocked = 'site_unblocked';
    case AutoUpdateBlocked = 'auto_update_blocked';
    case DailySummary = 'daily_summary';
}
