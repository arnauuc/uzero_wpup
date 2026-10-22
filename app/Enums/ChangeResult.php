<?php

declare(strict_types=1);

namespace App\Enums;

enum ChangeResult: string
{
    case Updated = 'updated';
    case Skipped = 'skipped';
    case Reverted = 'reverted';
    case Failed = 'failed';
}
