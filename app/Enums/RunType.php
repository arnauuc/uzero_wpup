<?php

declare(strict_types=1);

namespace App\Enums;

enum RunType: string
{
    case Inventory = 'inventory';
    case Plan = 'plan';
    case Update = 'update';
    case Backup = 'backup';
    case Restore = 'restore';
    case Rollback = 'rollback';
    case Verify = 'verify';
    case Cleanup = 'cleanup';
}
