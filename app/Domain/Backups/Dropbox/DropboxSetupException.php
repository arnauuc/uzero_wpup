<?php

declare(strict_types=1);

namespace App\Domain\Backups\Dropbox;

use RuntimeException;

/**
 * Why a Dropbox destination could not be set up. The messages are for the
 * operator (Catalan) and never carry credential values.
 */
final class DropboxSetupException extends RuntimeException {}
