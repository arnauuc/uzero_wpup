<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Unix identity of the current process: the effective user and, when started via
 * sudo, the user who invoked it (SUDO_USER is set by sudo and cannot be forged by
 * the caller once sudoers uses env_reset).
 */
final readonly class ProcessIdentity
{
    public function __construct(
        public string $effectiveUser,
        public ?string $sudoUser,
    ) {}

    public static function current(): self
    {
        $entry = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
        $effective = is_array($entry) ? $entry['name'] : (string) get_current_user();
        $sudoUser = getenv('SUDO_USER');

        return new self($effective, is_string($sudoUser) && $sudoUser !== '' ? $sudoUser : null);
    }
}
