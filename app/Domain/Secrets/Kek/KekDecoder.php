<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Kek;

use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStoreException;

/**
 * Decodes the base64 halves of the KEK and checks their length (X25519).
 */
final class KekDecoder
{
    public static function decode(#[\SensitiveParameter] string $encoded): Secret
    {
        $raw = self::raw($encoded, SODIUM_CRYPTO_BOX_SECRETKEYBYTES, 'private key');
        $key = new Secret($raw);
        sodium_memzero($raw);

        return $key;
    }

    public static function decodePublic(string $encoded): string
    {
        return self::raw($encoded, SODIUM_CRYPTO_BOX_PUBLICKEYBYTES, 'public key');
    }

    /**
     * The public key that belongs to a private key.
     */
    public static function publicKeyOf(Secret $privateKey): string
    {
        return sodium_crypto_box_publickey_from_secretkey($privateKey->reveal());
    }

    private static function raw(#[\SensitiveParameter] string $encoded, int $length, string $what): string
    {
        $raw = base64_decode(trim($encoded), true);

        if ($raw === false) {
            throw SecretStoreException::invalidKek("the {$what} is not valid base64.");
        }

        if (strlen($raw) !== $length) {
            sodium_memzero($raw);

            throw SecretStoreException::invalidKek("the {$what} must be {$length} bytes.");
        }

        return $raw;
    }
}
