<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

use RuntimeException;

/**
 * A download address the tool refuses to reach (not public, not an allowed
 * port, no DNS answer). Internal: its message (host only) goes to the log;
 * the operator gets the same generic message as for a connection error, so
 * nothing tells a probe apart from a real failure.
 */
final class DownloadBlocked extends RuntimeException {}
