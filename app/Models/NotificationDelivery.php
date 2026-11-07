<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationKind;
use App\Enums\NotificationSeverity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One notification sent (or tried) to one destination (UZEROWPUP-79). Holds
 * no message content: the masked destination and the redacted error only.
 *
 * @property int $id
 * @property NotificationKind $kind
 * @property NotificationSeverity $severity
 * @property int|null $run_id
 * @property int|null $site_id
 * @property int|null $notification_destination_id
 * @property string $channel
 * @property string $destination
 * @property NotificationDeliveryStatus $status
 * @property string|null $error
 * @property CarbonImmutable $created_at
 */
class NotificationDelivery extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => NotificationKind::class,
            'severity' => NotificationSeverity::class,
            'status' => NotificationDeliveryStatus::class,
        ];
    }
}
