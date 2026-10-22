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
}
