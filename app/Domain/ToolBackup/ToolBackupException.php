<?php

declare(strict_types=1);

namespace App\Domain\ToolBackup;

use RuntimeException;

/**
 * The tool's own backup could not be made, uploaded or pruned. The message is
 * for the operator (Catalan) and never contains secrets: command output is
 * redacted before it is quoted.
 */
final class ToolBackupException extends RuntimeException {}
