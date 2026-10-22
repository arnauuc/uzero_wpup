<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Kek;

use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStoreException;

/**
 * Development and test provider: one KEK (version 1) from WPUP_DEV_KEK.
 * Never used in production (ADR-0002): construction fails there.
 */
final readonly class EnvKekProvider implements KekProvider
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

    public function key(int $version): Secret
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
