<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Kek;

use App\Domain\Secrets\SecretStoreException;

/**
 * Production public keys (ADR-0011): WPUP_SECRETS_PUBLIC_KEY_V<n>, readable by
 * the service user. Derived from the private key when it is created.
 */
final readonly class ConfigPublicKeyRing implements PublicKeyRing
{
    /**
     * @param  array<int, string>  $encodedKeys  version => base64 public key
     */
    public function __construct(private array $encodedKeys, private int $currentVersion) {}

    public function currentVersion(): int
    {
        return $this->currentVersion;
    }

    public function publicKey(int $version): string
    {
        $encoded = $this->encodedKeys[$version] ?? null;

        if ($encoded === null || $encoded === '') {
            throw SecretStoreException::kekUnavailable("WPUP_SECRETS_PUBLIC_KEY_V{$version} is not set.");
        }

        return KekDecoder::decodePublic($encoded);
    }
}
