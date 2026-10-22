<?php

declare(strict_types=1);

namespace App\Domain\Secrets;

use RuntimeException;

/**
 * Failures of the secret store. Messages never contain secret material.
 */
final class SecretStoreException extends RuntimeException
{
    public static function kekUnavailable(string $reason): self
    {
        return new self('The key-encryption key is not available: '.$reason);
    }

    public static function invalidKek(string $reason): self
    {
        return new self('The key-encryption key is invalid: '.$reason);
    }

    public static function devProviderInProduction(): self
    {
        return new self('The "env" KEK provider is for development and tests only; it is refused when APP_ENV=production.');
    }

    public static function unknownProvider(string $name): self
    {
        return new self(sprintf('Unknown KEK provider "%s".', $name));
    }

    public static function notFound(string $id): self
    {
        return new self(sprintf('Secret %s does not exist.', $id));
    }

    public static function decryptionFailed(string $id): self
    {
        return new self(sprintf('Secret %s could not be decrypted (wrong key or tampered data).', $id));
    }
}
