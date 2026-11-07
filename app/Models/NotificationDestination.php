<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

/**
 * Where a notification channel sends (UZEROWPUP-79): the Laravel notifiable
 * of the notification system. The token or webhook of the channel is only in
 * the SecretStore (credential_ref); `address` is never secret, and is still
 * masked wherever it is shown or recorded.
 *
 * @property int $id
 * @property string $name
 * @property string $channel a key of wpup.notifications.channels
 * @property string|null $address
 * @property string|null $credential_ref
 * @property bool $active
 */
class NotificationDestination extends Model
{
    use Notifiable;

    /** Name of the built-in destination of the log channel, which is not a row. */
    public const string BUILT_IN_LOG = 'log';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * The tool's own log: always notified, from wpup.notifications.log_min_severity on.
     */
    public static function builtInLog(): self
    {
        return new self(['name' => self::BUILT_IN_LOG, 'channel' => 'log', 'address' => null, 'credential_ref' => null]);
    }

    /**
     * What a delivery records and the CLI shows: the name and the masked address.
     */
    public function label(): string
    {
        return $this->address === null || $this->address === '' ? $this->name : "{$this->name} ({$this->maskedAddress()})";
    }

    public function maskedAddress(): string
    {
        $address = (string) $this->address;
        $at = strrpos($address, '@');
        if ($at !== false && $at > 0) {
            return mb_substr($address, 0, 1).'***'.substr($address, $at);
        }

        return mb_strlen($address) > 6 ? mb_substr($address, 0, 2).'***'.mb_substr($address, -2) : '***';
    }

    /**
     * @return HasMany<NotificationSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(NotificationSubscription::class);
    }
}
