<?php

declare(strict_types=1);

namespace App\Domain\Drivers\Data;

use App\Enums\PhpVersionSource;

/**
 * The PHP a site runs with, as far as the driver can tell (UZEROWPUP-29): the
 * version, the binary WP-CLI uses, where it was learnt from and any warning for
 * the operator (configured binary overridden, handler unreadable...). The web
 * is the reference (AGENTS.md); the HTTP probe (UZEROWPUP-30) cross-checks it.
 */
final readonly class PhpRuntime
{
    /**
     * @param  list<string>  $warnings  operator-readable, in Catalan
     */
    public function __construct(
        public string $version,
        public string $binary,
        public PhpVersionSource $source,
        public array $warnings = [],
    ) {}

    /**
     * "8.3" from "8.3.14".
     */
    public function minor(): string
    {
        return implode('.', array_slice(explode('.', $this->version), 0, 2));
    }
}
