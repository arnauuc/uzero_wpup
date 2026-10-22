<?php

declare(strict_types=1);

namespace App\Domain\Sites\Import;

enum ImportAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Unchanged = 'unchanged';
}
