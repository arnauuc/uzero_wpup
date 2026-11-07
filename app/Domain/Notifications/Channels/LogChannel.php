<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Channels;

use App\Domain\Secrets\SecretRedactor;
use App\Enums\NotificationSeverity;
use App\Models\NotificationDestination;
use App\Notifications\WpupNotification;
use Illuminate\Support\Facades\Log;

/**
 * Writes the notification to the application log, at the level of its
 * severity. Backs the built-in destination every notification goes to.
 */
final readonly class LogChannel implements NotificationChannel
{
    public function __construct(private SecretRedactor $redactor) {}

    public function send(NotificationDestination $notifiable, WpupNotification $notification): void
    {
        Log::log(
            match ($notification->severity()) {
                NotificationSeverity::Info => 'info',
                NotificationSeverity::Warning => 'warning',
                NotificationSeverity::Error => 'error',
            },
            "Notification {$notification->kind()->value}: ".$this->redactor->redact($notification->subject()),
            [
                'site_id' => $notification->siteId,
                'run_id' => $notification->runId,
                'lines' => array_map($this->redactor->redact(...), $notification->lines()),
            ],
        );
    }
}
