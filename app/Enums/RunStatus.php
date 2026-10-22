<?php

declare(strict_types=1);

namespace App\Enums;

enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
    case Interrupted = 'interrupted';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';
}
