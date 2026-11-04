<?php

declare(strict_types=1);

namespace App\Enums;

enum PhpVersionSource: string
{
    case PleskHandler = 'plesk_handler';
    case HttpProbe = 'http_probe';
    case Configured = 'configured';
    // The SSH user's default `php`: not verified against what the web serves
    // until the HTTP probe (UZEROWPUP-30) cross-checks it.
    case Cli = 'cli';
}
