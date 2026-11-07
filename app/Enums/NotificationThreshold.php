<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Minimum severity a site or an operator wants to be told about (spec,
 * «Notificacions»): everything, warnings and errors, or errors only.
 */
enum NotificationThreshold: string
{
    case All = 'all';
    case Warnings = 'warnings';
    case Errors = 'errors';

    public function allows(NotificationSeverity $severity): bool
    {
        return $severity->rank() >= match ($this) {
            self::All => NotificationSeverity::Info->rank(),
            self::Warnings => NotificationSeverity::Warning->rank(),
            self::Errors => NotificationSeverity::Error->rank(),
        };
    }

    /**
     * Catalan text for the operator.
     */
    public function label(): string
    {
        return match ($this) {
            self::All => 'tot',
            self::Warnings => 'avisos i errors',
            self::Errors => 'només errors',
        };
    }
}
