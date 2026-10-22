<?php

declare(strict_types=1);

namespace App\Enums;

enum HostingPanel: string
{
    case Plesk = 'plesk';
    case Other = 'other';
}
