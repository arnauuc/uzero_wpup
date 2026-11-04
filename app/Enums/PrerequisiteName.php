<?php

declare(strict_types=1);

namespace App\Enums;

enum PrerequisiteName: string
{
    case PhpMinimum = 'php_minimum';
    case PhpVerified = 'php_verified';
    case BackupSpace = 'backup_space';
    case WpCli = 'wp_cli';
    case Writable = 'writable';
}
