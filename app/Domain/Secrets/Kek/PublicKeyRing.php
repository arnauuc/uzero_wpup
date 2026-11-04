<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Kek;

/**
 * Public half of the key-encryption key (ADR-0011). Not secret: every process
 * (CLI, web, workers) has it, so any of them can store a secret, but none can
 * read one back with it. Several versions coexist while `secrets:rekey` runs.
 */
interface PublicKeyRing
{
    /**
     * Version used to seal new data keys.
     */
    public function currentVersion(): int;

    /**
     * The raw 32-byte X25519 public key of a version.
     */
    public function publicKey(int $version): string;
}
