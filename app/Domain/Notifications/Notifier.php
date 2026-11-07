<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Notifications\WpupNotification;

/**
 * Tells the operators (UZEROWPUP-79). Never throws: a channel that fails is
 * recorded and logged, and whatever the caller was doing (a run, an
 * inventory, the scheduler) goes on.
 */
interface Notifier
{
    public function notify(WpupNotification $notification): void;
}
