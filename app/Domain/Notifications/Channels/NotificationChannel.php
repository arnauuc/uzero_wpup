<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Channels;

use App\Models\NotificationDestination;
use App\Notifications\WpupNotification;

/**
 * A Laravel notification channel of the tool (UZEROWPUP-79). A new channel
 * (email, Telegram, Slack…) is a class that implements this and an entry in
 * wpup.notifications.channels.
 *
 * - It throws on any failure; the Notifier records it (redacted) and goes on.
 * - A channel with a token or webhook reads it from the SecretStore through
 *   the destination's credential_ref (SecretStore::reveal(), which audits and
 *   registers the value with the redactor), only inside send(), and wipes it
 *   afterwards. It never logs it nor puts it in an exception message.
 */
interface NotificationChannel
{
    public function send(NotificationDestination $notifiable, WpupNotification $notification): void;
}
