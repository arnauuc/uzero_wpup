<?php

declare(strict_types=1);

namespace App\Enums;

enum UpdateStrategy: string
{
    case InPlace = 'in_place';
    case StagingFirst = 'staging_first';
}
