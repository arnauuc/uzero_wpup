<?php

declare(strict_types=1);

namespace App\Domain\Sites;

use RuntimeException;

/**
 * The operator's target selection is invalid. The message is shown as is.
 */
final class SiteSelectionException extends RuntimeException {}
