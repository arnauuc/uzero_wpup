<?php

declare(strict_types=1);

namespace App\Enums;

enum RunStepKind: string
{
    case Forward = 'forward';
    case Compensation = 'compensation';
}
