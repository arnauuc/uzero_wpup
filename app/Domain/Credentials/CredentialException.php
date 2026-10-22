<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

use RuntimeException;

/**
 * A key or secret operation cannot go on. The message is shown to the admin
 * and never contains secret material.
 */
final class CredentialException extends RuntimeException {}
