<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationThreshold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operator follows a destination from a minimum severity on (UZEROWPUP-79).
 *
 * @property int $id
 * @property int $user_id
 * @property int $notification_destination_id
 * @property NotificationThreshold $min_severity
 * @property-read User $user
 * @property-read NotificationDestination $destination
 */
class NotificationSubscription extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['min_severity' => 'all'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['min_severity' => NotificationThreshold::class];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<NotificationDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(NotificationDestination::class, 'notification_destination_id');
    }
}
