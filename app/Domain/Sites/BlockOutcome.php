<?php

declare(strict_types=1);

namespace App\Domain\Sites;

enum BlockOutcome: string
{
    /** Nothing changed (the site may still be blocked: see BlockResult::$reason). */
    case Unchanged = 'unchanged';
    case Blocked = 'blocked';
    case Unblocked = 'unblocked';
    /** Dry run: it would be blocked. */
    case WouldBlock = 'would_block';
    /** Dry run: it would be released. */
    case WouldUnblock = 'would_unblock';
}
