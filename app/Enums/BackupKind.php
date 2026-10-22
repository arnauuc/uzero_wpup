<?php

declare(strict_types=1);

namespace App\Enums;

enum BackupKind: string
{
    case Database = 'database';
    case Files = 'files';
}
