<?php

declare(strict_types=1);

namespace App\Enums;

enum CheckResult: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
}
