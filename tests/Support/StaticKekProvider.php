<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Secrets\EnvelopeSecretStore;
use App\Domain\Secrets\Kek\KekDecoder;
use App\Domain\Secrets\Kek\KekProvider;
use App\Domain\Secrets\Kek\PublicKeyRing;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStore;
use App\Domain\Secrets\SecretStoreException;

/**
 * Test key pairs with several versions, to exercise secrets:rekey. With
 * $withPrivateKeys = false it behaves like the CLI or the web in production:
 * public keys only.
 */
final readonly class StaticKekProvider implements KekProvider, PublicKeyRing
{
    /**
     * @param  array<int, string>  $keys  version => base64 X25519 private key
     */
    public function __construct(private array $keys, private int $current, private bool $withPrivateKeys = true) {}

    /**
     * Binds this provider as both halves of the KEK and forgets the store.
     */
    public function bind(): void
    {
        app()->forgetInstance(EnvelopeSecretStore::class);
        app()->forgetInstance(SecretStore::class);
        app()->instance(KekProvider::class, $this);
        app()->instance(PublicKeyRing::class, $this);
    }

    public function currentVersion(): int
    {
        return $this->current;
    }

    public function publicKey(int $version): string
    {
        $private = $this->decode($version);

        try {
            return KekDecoder::publicKeyOf($private);
        } finally {
            $private->wipe();
        }
    }

    public function privateKey(int $version): Secret
    {
        return $this->withPrivateKeys
            ? $this->decode($version)
            : throw SecretStoreException::kekUnavailable('this test process has no private key.');
    }

    private function decode(int $version): Secret
    {
        return isset($this->keys[$version])
            ? KekDecoder::decode($this->keys[$version])
            : throw SecretStoreException::kekUnavailable("no test KEK v{$version}");
    }
}
