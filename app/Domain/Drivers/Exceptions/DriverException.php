<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Exceptions;

use RuntimeException;

/**
 * A driver operation failed. The message is shown to operators (Catalan) and
 * never contains secrets.
 */
class DriverException extends RuntimeException {}
