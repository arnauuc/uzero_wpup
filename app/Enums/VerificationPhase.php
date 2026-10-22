<?php

declare(strict_types=1);

namespace App\Enums;

enum VerificationPhase: string
{
    case Baseline = 'baseline';
    case After = 'after';
}
