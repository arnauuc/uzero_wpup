<?php

declare(strict_types=1);

namespace App\Enums;

enum RunStepKind: string
{
    case Forward = 'forward';
    case Compensation = 'compensation';
    /** A step of the pipeline's recovery branch (Pipeline::$recovery), run after a failure instead of the compensations. */
    case Recovery = 'recovery';
}
