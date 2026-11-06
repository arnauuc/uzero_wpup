<?php

declare(strict_types=1);

namespace App\Domain\Planning;

/**
 * Why an update does not wait for the quarantine (ADR-0005 sections 3 and 4).
 */
enum QuarantineBypass: string
{
    /** Core x.y.z within the installed x.y branch. */
    case CoreMaintenance = 'core_maintenance';

    /**
     * Catalan text for the operator.
     */
    public function label(): string
    {
        return match ($this) {
            self::CoreMaintenance => 'manteniment del core: sense quarantena',
        };
    }
}
