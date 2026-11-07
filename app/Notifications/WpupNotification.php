<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Notifications\NotificationChannels;
use App\Enums\NotificationKind;
use App\Enums\NotificationSeverity;
use App\Models\NotificationDestination;
use Illuminate\Notifications\Notification;

/**
 * A notification of the tool (UZEROWPUP-79), sent through Laravel's
 * notification system to NotificationDestination notifiables. Each kind sets
 * its severity, which the Notifier compares with the minimum severity of the
 * site and of each operator. The text is for the operator (Catalan) and must
 * never hold secrets: it is built from data that is already redacted, and the
 * channels redact it again.
 *
 * A channel receives it in send($destination, $notification) and reads
 * subject() and lines(); the channel of the destination is the only one in via().
 */
abstract class WpupNotification extends Notification
{
    public function __construct(
        public readonly ?int $siteId = null,
        public readonly ?int $runId = null,
    ) {}

    abstract public function kind(): NotificationKind;

    abstract public function severity(): NotificationSeverity;

    abstract public function subject(): string;

    /**
     * @return list<string>
     */
    abstract public function lines(): array;

    /**
     * @return list<string>
     */
    public function via(NotificationDestination $notifiable): array
    {
        return [app(NotificationChannels::class)->classFor($notifiable->channel)];
    }

    /**
     * How the operator reads a run's report, as a closing line.
     */
    protected static function reportLine(int $runId): string
    {
        return "Informe: wpup report {$runId}";
    }
}
