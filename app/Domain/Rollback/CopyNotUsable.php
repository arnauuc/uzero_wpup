<?php

declare(strict_types=1);

namespace App\Domain\Rollback;

use RuntimeException;

/**
 * A copy refused right before its import (UZEROWPUP-59): it is not on the
 * site any more, or holds other bytes. Nothing was imported from it, so the
 * next copy of the point is tried.
 */
final class CopyNotUsable extends RuntimeException {}
