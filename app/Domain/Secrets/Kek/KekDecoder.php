<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Kek;

use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStoreException;

/**
 * Decodes a base64 KEK and checks it has the length the cipher needs.
 */
final class KekDecoder
{
    public static function decode(#[\SensitiveParameter] string $encoded): Secret
    {
        $raw = base64_decode($encoded, true);

        if ($raw === false) {
            throw SecretStoreException::invalidKek('it is not valid base64.');
        }

        if (strlen($raw) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            sodium_memzero($raw);

            throw SecretStoreException::invalidKek(sprintf('it must be %d bytes.', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
        }

        $key = new Secret($raw);
        sodium_memzero($raw);

        return $key;
    }
}
