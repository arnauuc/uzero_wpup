<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Kek;

use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStoreException;

/**
 * Private half of the key-encryption key (ADR-0011). Only the services that
 * systemd hands the sealed credential to (Horizon workers and the scheduler)
 * have it; in the CLI and the web it is unavailable by design.
 */
interface KekProvider
{
    /**
     * The raw 32-byte X25519 secret key of a version.
     *
     * @throws SecretStoreException when this process has no private key
     */
    public function privateKey(int $version): Secret;
}
