<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use RuntimeException;

/**
 * The order and user watermark could not be read on the site (UZEROWPUP-58).
 * The message is for operators (Catalan), redacted.
 */
final class WatermarkUnreadable extends RuntimeException {}
