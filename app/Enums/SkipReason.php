<?php

declare(strict_types=1);

namespace App\Enums;

enum SkipReason: string
{
    case PhpIncompatible = 'php_incompatible';
    case WpIncompatible = 'wp_incompatible';
    case StaticAnalysis = 'static_analysis';
    case Dependency = 'dependency';
    case Frozen = 'frozen';
    case Excluded = 'excluded';
    case NoLicense = 'no_license';
    case Quarantine = 'quarantine';
    case MajorRequiresStaging = 'major_requires_staging';
    case SiteBlocked = 'site_blocked';
    case Other = 'other';

    /**
     * Catalan text for the operator (plan tables, reports).
     */
    public function label(): string
    {
        return match ($this) {
            self::PhpIncompatible => 'PHP incompatible',
            self::WpIncompatible => 'WordPress incompatible',
            self::StaticAnalysis => "l'anàlisi estàtica hi veu problemes",
            self::Dependency => 'dependència no satisfeta',
            self::Frozen => 'component congelat',
            self::Excluded => 'component exclòs',
            self::NoLicense => 'sense llicència',
            self::Quarantine => 'en quarantena',
            self::MajorRequiresStaging => 'major pendent: cal staging (F7)',
            self::SiteBlocked => 'lloc bloquejat',
            self::Other => 'altre motiu',
        };
    }
}
