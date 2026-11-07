<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How serious a notification is (UZEROWPUP-79). Compared against the minimum
 * severity of each site and operator (NotificationThreshold).
 */
enum NotificationSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Error => 2,
        };
    }

    /**
     * Catalan text for the operator.
     */
    public function label(): string
    {
        return match ($this) {
            self::Info => 'informació',
            self::Warning => 'avís',
            self::Error => 'error',
        };
    }
}
