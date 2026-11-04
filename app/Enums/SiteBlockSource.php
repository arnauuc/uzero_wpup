<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who blocked a site for updates (UZEROWPUP-34). The tool lifts only its own
 * blocks; an operator's block stays until an operator lifts it.
 */
enum SiteBlockSource: string
{
    case Prerequisites = 'prerequisites';
    case Operator = 'operator';
}
