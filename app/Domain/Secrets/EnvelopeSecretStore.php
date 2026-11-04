<?php

declare(strict_types=1);

namespace App\Domain\Secrets;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Secrets\Kek\KekConfirmation;
use App\Domain\Secrets\Kek\KekDecoder;
use App\Domain\Secrets\Kek\KekProvider;
use App\Domain\Secrets\Kek\PublicKeyRing;
use App\Enums\AuditResult;
use App\Models\StoredSecret;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SodiumException;

/**
 * Sealed envelope encryption with libsodium (ADR-0011, format v2).
 *
 * - Each secret is encrypted with its own random XChaCha20-Poly1305 data key
 *   (DEK), with the secret id as associated data.
 * - The DEK is sealed with `crypto_box_seal` and the X25519 public key of the
 *   current KEK version. The sealed payload is DEK || version || id, and both are
 *   checked against the row on opening, so a payload copied to another row, or
 *   relabelled with another version, does not open.
 *
 * Storing needs only the public key, so the CLI and the web can do it, once the
 * scheduler has confirmed that key against its private half (KekConfirmation).
 * Reading needs the private key, which only the worker services have.
 */
final readonly class EnvelopeSecretStore implements SecretStore
{
    private const FORMAT = 'v2';

    private const DEK_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;

    public function __construct(
        private PublicKeyRing $publicKeys,
        private KekProvider $privateKeys,
        private AuditLogger $audit,
        private SecretRedactor $redactor,
        private KekConfirmation $confirmation,
    ) {}

    public function put(string $purpose, Secret $value, AuditActor $actor): string
    {
        $id = (string) Str::uuid7();

        StoredSecret::query()->create(['id' => $id, 'purpose' => $purpose] + $this->seal($id, $value));

        $this->audit->record($actor, 'secret.store', AuditResult::Success, 'secret', $id, ['purpose' => $purpose]);

        return $id;
    }

    public function replace(string $id, Secret $value, AuditActor $actor): void
    {
        $row = $this->find($id);
        $row->fill($this->seal($id, $value))->save();

        $this->audit->record($actor, 'secret.replace', AuditResult::Success, 'secret', $id, ['purpose' => $row->purpose]);
    }

    public function reveal(string $id, AuditActor $actor, string $reason): Secret
    {
        $row = $this->find($id);
        $context = ['purpose' => $row->purpose, 'reason' => $reason];

        try {
            $secret = $this->open($row);
        } catch (SecretStoreException $e) {
            $this->audit->record($actor, 'secret.reveal', AuditResult::Failure, 'secret', $id, $context);

            throw $e;
        }

        $this->audit->record($actor, 'secret.reveal', AuditResult::Success, 'secret', $id, $context);

        // From now on, any output of this process that contains it is masked.
        $this->redactor->register($secret);

        return $secret;
    }

    /**
     * Re-seals the secret's data key with the current KEK version (`secrets:rekey`).
     * The ciphertext is untouched. Idempotent: returns false when the secret is
     * already on the current version. Needs the old and the new private key, so
     * it only runs on the workers; each row is checked to open with the new key
     * before the transaction commits.
     */
    public function rewrap(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $row = StoredSecret::query()->whereKey($id)->lockForUpdate()->first() ?? throw SecretStoreException::notFound($id);
            $current = $this->publicKeys->currentVersion();

            if ($row->kek_version === $current) {
                return false;
            }

            $dek = $this->openDek($row);

            try {
                $sealed = $this->sealDek($dek, $row->id, $current);
            } finally {
                sodium_memzero($dek);
            }

            $row->fill(['wrapped_dek' => $sealed, 'kek_version' => $current])->save();
            $this->open($row)->wipe();

            return true;
        });
    }

    private function find(string $id): StoredSecret
    {
        return StoredSecret::query()->find($id) ?? throw SecretStoreException::notFound($id);
    }

    /**
     * @return array{ciphertext: string, nonce: string, wrapped_dek: string, kek_version: int}
     */
    private function seal(string $id, Secret $value): array
    {
        $version = $this->publicKeys->currentVersion();
        $dek = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($value->reveal(), self::dataAd($id), $nonce, $dek);
            $sealed = $this->sealDek($dek, $id, $version);
        } finally {
            sodium_memzero($dek);
        }

        return [
            'ciphertext' => base64_encode($ciphertext),
            'nonce' => base64_encode($nonce),
            'wrapped_dek' => $sealed,
            'kek_version' => $version,
        ];
    }

    /**
     * Seals DEK || version || id with the public key of $version (base64).
     */
    private function sealDek(#[\SensitiveParameter] string $dek, string $id, int $version): string
    {
        $public = $this->publicKeys->publicKey($version);
        // Never seal to a public key whose private half nobody holds.
        $this->confirmation->assertSealable($version, $public);
        $payload = $dek.pack('N', $version).$id;

        try {
            return base64_encode(sodium_crypto_box_seal($payload, $public));
        } finally {
            sodium_memzero($payload);
        }
    }

    /**
     * Opens the sealed DEK of a row with the private key of its version and checks
     * that the payload belongs to that row and version. The caller wipes the DEK.
     */
    private function openDek(StoredSecret $row): string
    {
        $private = $this->privateKeys->privateKey($row->kek_version);
        $keypair = '';
        $payload = false;

        try {
            $public = $this->publicKeys->publicKey($row->kek_version);

            // A private key that does not match the published public key would
            // let new secrets be sealed to a key nobody holds: fail loudly.
            if (! hash_equals($public, KekDecoder::publicKeyOf($private))) {
                throw SecretStoreException::invalidKek(sprintf('the private key v%d does not match WPUP_SECRETS_PUBLIC_KEY_V%d.', $row->kek_version, $row->kek_version));
            }

            $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($private->reveal(), $public);
            $payload = sodium_crypto_box_seal_open(self::decode($row->wrapped_dek, $row->id), $keypair);

            if ($payload === false || strlen($payload) !== self::DEK_BYTES + 4 + strlen($row->id)) {
                throw SecretStoreException::decryptionFailed($row->id);
            }

            $version = unpack('N', substr($payload, self::DEK_BYTES, 4));
            $boundId = substr($payload, self::DEK_BYTES + 4);

            if (! is_array($version) || $version[1] !== $row->kek_version || ! hash_equals($row->id, $boundId)) {
                throw SecretStoreException::decryptionFailed($row->id);
            }

            return substr($payload, 0, self::DEK_BYTES);
        } catch (SodiumException) {
            throw SecretStoreException::decryptionFailed($row->id);
        } finally {
            if (is_string($payload)) {
                sodium_memzero($payload);
            }
            if ($keypair !== '') {
                sodium_memzero($keypair);
            }
            $private->wipe();
        }
    }

    private function open(StoredSecret $row): Secret
    {
        $dek = $this->openDek($row);

        try {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                self::decode($row->ciphertext, $row->id),
                self::dataAd($row->id),
                self::decode($row->nonce, $row->id),
                $dek,
            );
        } catch (SodiumException) {
            throw SecretStoreException::decryptionFailed($row->id);
        } finally {
            sodium_memzero($dek);
        }

        if ($plain === false) {
            throw SecretStoreException::decryptionFailed($row->id);
        }

        $secret = new Secret($plain);
        sodium_memzero($plain);

        return $secret;
    }

    private static function decode(string $encoded, string $id): string
    {
        $raw = base64_decode($encoded, true);

        return $raw === false ? throw SecretStoreException::decryptionFailed($id) : $raw;
    }

    private static function dataAd(string $id): string
    {
        return 'wpup-secret:'.self::FORMAT.':'.$id;
    }
}
