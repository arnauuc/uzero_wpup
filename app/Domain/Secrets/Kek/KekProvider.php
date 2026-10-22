<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Kek;

use App\Domain\Secrets\Secret;

/**
 * Source of the key-encryption key (ADR-0002). Several versions can coexist
 * while `secrets:rekey` re-wraps the data keys (UZEROWPUP-123).
 */
interface KekProvider
{
    /**
     * Version used to wrap new data keys.
     */
    public function currentVersion(): int;

    /**
     * The raw 32-byte key for a version.
     */
    public function key(int $version): Secret;
}
