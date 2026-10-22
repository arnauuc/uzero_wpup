<?php

declare(strict_types=1);

namespace App\Enums;

enum PhpVersionSource: string
{
    case PleskHandler = 'plesk_handler';
    case HttpProbe = 'http_probe';
    case Configured = 'configured';
}
