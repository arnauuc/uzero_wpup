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

    /** The installed version has a known vulnerability that this update fixes. */
    case Vulnerability = 'vulnerability';

    /** An admin passed --skip-quarantine and the version was still in quarantine. */
    case Override = 'skip_quarantine';

    /**
     * Catalan text for the operator.
     */
    public function label(): string
    {
        return match ($this) {
            self::CoreMaintenance => 'manteniment del core: sense quarantena',
            self::Vulnerability => 'excepció de seguretat: sense quarantena',
            self::Override => 'quarantena omesa amb --skip-quarantine',
        };
    }
}
