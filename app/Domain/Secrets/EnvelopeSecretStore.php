<?php

declare(strict_types=1);

namespace App\Domain\Secrets;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Secrets\Kek\KekProvider;
use App\Enums\AuditResult;
use App\Models\StoredSecret;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SodiumException;

/**
 * Envelope encryption with libsodium (ADR-0002): a random XChaCha20-Poly1305 data
 * key per secret, wrapped with the KEK. The secret id and KEK version are bound as
 * associated data, so a ciphertext copied to another row does not decrypt.
 */
final readonly class EnvelopeSecretStore implements SecretStore
{
    private const FORMAT = 'v1';

    public function __construct(
        private KekProvider $kekProvider,
        private AuditLogger $audit,
        private SecretRedactor $redactor,
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
     * Re-wraps the secret's data key with the current KEK (`secrets:rekey`).
     * The ciphertext is untouched. Idempotent: returns false when the secret is
     * already on the current KEK. Each row is checked to open with the new KEK
     * before the transaction commits.
     */
    public function rewrap(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $row = StoredSecret::query()->whereKey($id)->lockForUpdate()->first() ?? throw SecretStoreException::notFound($id);
            $current = $this->kekProvider->currentVersion();

            if ($row->kek_version === $current) {
                return false;
            }

            $old = $this->kekProvider->key($row->kek_version);
            $new = $this->kekProvider->key($current);
            $dek = false;
            $dekNonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

            try {
                $dek = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                    self::decode($row->wrapped_dek, $row->id),
                    self::dekAd($row->id, $row->kek_version),
                    self::decode($row->dek_nonce, $row->id),
                    $old->reveal(),
                );
                if ($dek === false) {
                    throw SecretStoreException::decryptionFailed($row->id);
                }
                $wrapped = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dek, self::dekAd($row->id, $current), $dekNonce, $new->reveal());
            } catch (SodiumException) {
                throw SecretStoreException::decryptionFailed($row->id);
            } finally {
                if (is_string($dek)) {
                    sodium_memzero($dek);
                }
                $old->wipe();
                $new->wipe();
            }

            $row->fill(['wrapped_dek' => base64_encode($wrapped), 'dek_nonce' => base64_encode($dekNonce), 'kek_version' => $current])->save();
            $this->open($row)->wipe();

            return true;
        });
    }

    private function find(string $id): StoredSecret
    {
        return StoredSecret::query()->find($id) ?? throw SecretStoreException::notFound($id);
    }

    /**
     * @return array{ciphertext: string, nonce: string, wrapped_dek: string, dek_nonce: string, kek_version: int}
     */
    private function seal(string $id, Secret $value): array
    {
        $version = $this->kekProvider->currentVersion();
        $kek = $this->kekProvider->key($version);
        $dek = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $dekNonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($value->reveal(), self::dataAd($id), $nonce, $dek);
            $wrappedDek = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dek, self::dekAd($id, $version), $dekNonce, $kek->reveal());
        } finally {
            sodium_memzero($dek);
            $kek->wipe();
        }

        return [
            'ciphertext' => base64_encode($ciphertext),
            'nonce' => base64_encode($nonce),
            'wrapped_dek' => base64_encode($wrappedDek),
            'dek_nonce' => base64_encode($dekNonce),
            'kek_version' => $version,
        ];
    }

    private function open(StoredSecret $row): Secret
    {
        $kek = $this->kekProvider->key($row->kek_version);
        $dek = false;

        try {
            $dek = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                self::decode($row->wrapped_dek, $row->id),
                self::dekAd($row->id, $row->kek_version),
                self::decode($row->dek_nonce, $row->id),
                $kek->reveal(),
            );

            if ($dek === false) {
                throw SecretStoreException::decryptionFailed($row->id);
            }

            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                self::decode($row->ciphertext, $row->id),
                self::dataAd($row->id),
                self::decode($row->nonce, $row->id),
                $dek,
            );

            if ($plain === false) {
                throw SecretStoreException::decryptionFailed($row->id);
            }
        } catch (SodiumException) {
            throw SecretStoreException::decryptionFailed($row->id);
        } finally {
            if (is_string($dek)) {
                sodium_memzero($dek);
            }
            $kek->wipe();
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

    private static function dekAd(string $id, int $kekVersion): string
    {
        return 'wpup-dek:'.self::FORMAT.':'.$id.':'.$kekVersion;
    }
}
