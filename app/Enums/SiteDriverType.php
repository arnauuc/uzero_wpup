<?php

declare(strict_types=1);

namespace App\Enums;

enum SiteDriverType: string
{
    case Ssh = 'ssh';
    case Agent = 'agent';
}
