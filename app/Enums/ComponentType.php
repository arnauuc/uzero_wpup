<?php

declare(strict_types=1);

namespace App\Enums;

enum ComponentType: string
{
    case Core = 'core';
    case Woocommerce = 'woocommerce';
    case Plugin = 'plugin';
    case Theme = 'theme';
    case Translation = 'translation';
    case Dropin = 'dropin';
    case MuPlugin = 'mu_plugin';
}
