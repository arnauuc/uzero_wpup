<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Audit\AuditActor;
use App\Domain\Notifications\Channels\NotificationChannel;
use App\Domain\Secrets\SecretStore;
use App\Models\NotificationDestination;
use App\Notifications\WpupNotification;
use RuntimeException;

/**
 * Test channel (UZEROWPUP-79): records what it is asked to send and, when
 * told to, fails. Like a real channel, it reads the destination's secret
 * from the SecretStore; `$leak` makes it fail with that secret in the message.
 */
final class FakeNotificationChannel implements NotificationChannel
{
    public const string NAME = 'fake';

    /** @var list<array{destination: string, kind: string, severity: string, subject: string}> */
    public array $sent = [];

    public bool $fail = false;

    public bool $leak = false;

    public function __construct(private readonly SecretStore $secrets) {}

    /**
     * Registers the channel in the configuration and the container.
     */
    public static function install(): self
    {
        config(['wpup.notifications.channels.'.self::NAME => ['class' => self::class, 'address' => true, 'secret' => true]]);
        $fake = new self(app(SecretStore::class));
        app()->instance(self::class, $fake);

        return $fake;
    }

    public function send(NotificationDestination $notifiable, WpupNotification $notification): void
    {
        $token = $notifiable->credential_ref === null ? null : $this->secrets->reveal($notifiable->credential_ref, AuditActor::system('tests'), 'notification');
        try {
            if ($this->leak) {
                throw new RuntimeException('POST https://chat.example/bot'.$token?->reveal().'/send: 401');
            }
            if ($this->fail) {
                throw new RuntimeException('the chat service is down');
            }
            $this->sent[] = [
                'destination' => $notifiable->name,
                'kind' => $notification->kind()->value,
                'severity' => $notification->severity()->value,
                'subject' => $notification->subject(),
            ];
        } finally {
            $token?->wipe();
        }
    }
}
