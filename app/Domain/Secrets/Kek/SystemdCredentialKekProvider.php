<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Kek;

use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStoreException;

/**
 * Production private keys (ADR-0011): the X25519 secret key that systemd decrypts
 * from the TPM-sealed credential and exposes in $CREDENTIALS_DIRECTORY
 * (LoadCredentialEncrypted=). Only the worker and scheduler units load it; the
 * CLI and the web never have it, so anything that reveals a secret runs on the
 * workers. The file holds the key base64-encoded, as kept in the custodian's KeePassXC.
 */
final readonly class SystemdCredentialKekProvider implements KekProvider
{
    public function __construct(
        private ?string $credentialsDirectory,
        private string $credentialPrefix,
    ) {}

    public function privateKey(int $version): Secret
    {
        if ($this->credentialsDirectory === null || $this->credentialsDirectory === '') {
            throw SecretStoreException::kekUnavailable('this process has no private key ($CREDENTIALS_DIRECTORY is not set). Only the wpup worker services can read secrets (ADR-0011).');
        }

        $path = rtrim($this->credentialsDirectory, '/').'/'.$this->credentialPrefix.$version;

        if (! is_readable($path)) {
            throw SecretStoreException::kekUnavailable(sprintf('credential "%s%d" is not readable.', $this->credentialPrefix, $version));
        }

        $encoded = file_get_contents($path);

        if ($encoded === false) {
            throw SecretStoreException::kekUnavailable(sprintf('credential "%s%d" could not be read.', $this->credentialPrefix, $version));
        }

        try {
            return KekDecoder::decode($encoded);
        } finally {
            sodium_memzero($encoded);
        }
    }
}
