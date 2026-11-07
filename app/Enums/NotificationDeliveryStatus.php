<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Outcome of one notification sent to one destination.
 */
enum NotificationDeliveryStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
}
