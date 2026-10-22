<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Kek;

use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStoreException;

/**
 * Production provider: reads the KEK that systemd decrypts from the TPM-sealed
 * credential and exposes in $CREDENTIALS_DIRECTORY (LoadCredentialEncrypted=).
 * The file holds the key base64-encoded, as kept in the custodian's KeePassXC.
 */
final readonly class SystemdCredentialKekProvider implements KekProvider
{
    public function __construct(
        private ?string $credentialsDirectory,
        private string $credentialPrefix,
        private int $currentVersion,
    ) {}

    public function currentVersion(): int
    {
        return $this->currentVersion;
    }

    public function key(int $version): Secret
    {
        if ($this->credentialsDirectory === null || $this->credentialsDirectory === '') {
            throw SecretStoreException::kekUnavailable('$CREDENTIALS_DIRECTORY is not set (is the process running under the wpup systemd unit?).');
        }

        $path = rtrim($this->credentialsDirectory, '/').'/'.$this->credentialPrefix.$version;

        if (! is_readable($path)) {
            throw SecretStoreException::kekUnavailable(sprintf('credential "%s%d" is not readable.', $this->credentialPrefix, $version));
        }

        $encoded = file_get_contents($path);

        if ($encoded === false) {
            throw SecretStoreException::kekUnavailable(sprintf('credential "%s%d" could not be read.', $this->credentialPrefix, $version));
        }

        return KekDecoder::decode(trim($encoded));
    }
}
