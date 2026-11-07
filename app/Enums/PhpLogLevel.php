<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Severity of a PHP error log line (UZEROWPUP-71). Notice also covers
 * deprecations and strict-standards messages.
 */
enum PhpLogLevel: string
{
    case Fatal = 'fatal';
    case Warning = 'warning';
    case Notice = 'notice';
}
