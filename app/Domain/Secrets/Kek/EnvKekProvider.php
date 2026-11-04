<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Kek;

use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStoreException;

/**
 * Development and test provider: one X25519 private key (version 1) from
 * WPUP_DEV_KEK, whose public key is derived from it. Every process has the
 * private key here, unlike production. Construction fails when APP_ENV=production.
 */
final readonly class EnvKekProvider implements KekProvider, PublicKeyRing
{
    public function __construct(
        #[\SensitiveParameter] private ?string $encodedKey,
        string $environment,
    ) {
        if ($environment === 'production') {
            throw SecretStoreException::devProviderInProduction();
        }
    }

    public function currentVersion(): int
    {
        return 1;
    }

    public function publicKey(int $version): string
    {
        $private = $this->privateKey($version);

        try {
            return KekDecoder::publicKeyOf($private);
        } finally {
            $private->wipe();
        }
    }

    public function privateKey(int $version): Secret
    {
        if ($version !== 1) {
            throw SecretStoreException::kekUnavailable(sprintf('the env provider only has version 1, not %d.', $version));
        }

        if ($this->encodedKey === null || $this->encodedKey === '') {
            throw SecretStoreException::kekUnavailable('WPUP_DEV_KEK is empty (run `make init`).');
        }

        return KekDecoder::decode($this->encodedKey);
    }
}
