<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Secrets\SecretRedactor;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationThreshold;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;
use App\Models\NotificationSubscription;
use App\Models\SiteProfile;
use App\Notifications\WpupNotification;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Routes a notification to its destinations and records each delivery
 * (UZEROWPUP-79):
 *
 * - The built-in log destination, from wpup.notifications.log_min_severity on.
 * - The destinations the active operators follow (notification_subscriptions),
 *   from each operator's minimum severity on. A notification about a site
 *   also needs the site's minimum severity, and when the site lists channels
 *   (site_profiles.notification_channels) only those are used; an empty list
 *   means every channel. A destination followed by several operators gets
 *   the notification once.
 *
 * Each delivery is a row in notification_deliveries (masked destination,
 * redacted error); a run's rows are part of its report. Nothing here throws.
 */
final readonly class ChannelNotifier implements Notifier
{
    public function __construct(
        private Dispatcher $sender,
        private SecretRedactor $redactor,
        private NotificationThreshold $logThreshold,
        private int $errorMaxLength,
    ) {}

    public function notify(WpupNotification $notification): void
    {
        try {
            $destinations = $this->destinations($notification);
        } catch (Throwable $e) {
            Log::error('Could not resolve the notification destinations.', ['kind' => $notification->kind()->value, 'error' => $this->redactor->redact($e->getMessage())]);

            return;
        }

        foreach ($destinations as $destination) {
            $error = null;
            try {
                $this->sender->sendNow($destination, $notification);
            } catch (Throwable $e) {
                $error = mb_substr($this->redactor->redact($e->getMessage()), 0, $this->errorMaxLength);
                Log::warning('Notification delivery failed.', ['kind' => $notification->kind()->value, 'channel' => $destination->channel, 'destination' => $destination->name, 'error' => $error]);
            }
            $this->record($notification, $destination, $error);
        }
    }

    /**
     * @return list<NotificationDestination>
     */
    public function destinations(WpupNotification $notification): array
    {
        $severity = $notification->severity();
        $destinations = $this->logThreshold->allows($severity) ? [NotificationDestination::builtInLog()] : [];

        $profile = $notification->siteId === null ? null : SiteProfile::query()->where('site_id', $notification->siteId)->first();
        if ($profile !== null && ! $profile->notification_min_severity->allows($severity)) {
            return $destinations;
        }
        $channels = $profile === null ? [] : $profile->notification_channels;

        $subscriptions = NotificationSubscription::query()->with('destination')
            ->whereHas('user', static fn ($q) => $q->where('active', true))
            ->whereHas('destination', static fn ($q) => $q->where('active', true))
            ->orderBy('notification_destination_id')
            ->get();
        $followed = [];
        foreach ($subscriptions as $subscription) {
            $destination = $subscription->destination;
            if ($subscription->min_severity->allows($severity)
                && ($channels === [] || in_array($destination->channel, $channels, true))) {
                $followed[$destination->id] = $destination;
            }
        }

        return [...$destinations, ...array_values($followed)];
    }

    private function record(WpupNotification $notification, NotificationDestination $destination, ?string $error): void
    {
        try {
            NotificationDelivery::query()->create([
                'kind' => $notification->kind(),
                'severity' => $notification->severity(),
                'run_id' => $notification->runId,
                'site_id' => $notification->siteId,
                'notification_destination_id' => $destination->exists ? $destination->id : null,
                'channel' => $destination->channel,
                'destination' => $this->redactor->redact($destination->label()),
                'status' => $error === null ? NotificationDeliveryStatus::Sent : NotificationDeliveryStatus::Failed,
                'error' => $error,
            ]);
        } catch (Throwable $e) {
            Log::error('Could not record a notification delivery.', ['kind' => $notification->kind()->value, 'destination' => $destination->name, 'error' => $this->redactor->redact($e->getMessage())]);
        }
    }
}
