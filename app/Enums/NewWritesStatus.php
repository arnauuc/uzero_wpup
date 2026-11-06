<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a shop's orders or users were written since a copy (UZEROWPUP-58).
 * Unknown always counts as written (AGENTS.md rule 4).
 */
enum NewWritesStatus: string
{
    case None = 'none';
    case Found = 'found';
    case Unknown = 'unknown';
}
