<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditChannel: string
{
    case Cli = 'cli';
    case Web = 'web';
    case System = 'system';
}
