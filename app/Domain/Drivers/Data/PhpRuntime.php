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
    /** What a PHP_VERSION looks like ("8.3.14", "7.4.33-1+deb11u1"). */
    public const string VERSION_PATTERN = '/^\d+\.\d+\.\d+[A-Za-z0-9.+~-]{0,40}$/';

    /** The warning of an unverified CLI PHP; the HTTP probe can lift it. */
    public const string UNVERIFIED_WARNING = 'Es fa servir el PHP per defecte del compte (`php`), que pot no ser el que serveix el web.';

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
        return self::minorOf($this->version);
    }

    public static function minorOf(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }

    public static function isVersion(string $value): bool
    {
        return preg_match(self::VERSION_PATTERN, $value) === 1;
    }
}
