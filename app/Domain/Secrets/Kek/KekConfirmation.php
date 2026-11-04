<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Kek;

use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStoreException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Guards against sealing secrets to a public key that nobody holds the private
 * half of (ADR-0011). The CLI and the web only have the public keys, so they
 * cannot check the pair themselves: a process that has the private key (the
 * scheduler, every minute) confirms it in `kek_confirmations`, and sealing is
 * refused until the current public key is confirmed there.
 */
final readonly class KekConfirmation
{
    private const TABLE = 'kek_confirmations';

    public function __construct(
        private PublicKeyRing $publicKeys,
        private KekProvider $privateKeys,
    ) {}

    /**
     * Checks the current version's key pair and records the outcome. Runs where
     * the private key is loaded; a mismatch withdraws the confirmation, so the
     * CLI and the web stop sealing with that public key.
     *
     * @throws SecretStoreException when this process has no private key
     */
    public function confirm(): bool
    {
        $version = $this->publicKeys->currentVersion();
        $public = $this->publicKeys->publicKey($version);

        if (! self::matches($this->privateKeys->privateKey($version), $public)) {
            DB::table(self::TABLE)->where('version', $version)->delete();
            Log::critical('The KEK private key does not match its public key: sealing new secrets is refused.', ['version' => $version]);

            return false;
        }

        DB::table(self::TABLE)->upsert([[
            'version' => $version,
            'public_key_sha256' => hash('sha256', $public),
            'confirmed_at' => now(),
        ]], ['version'], ['public_key_sha256', 'confirmed_at']);

        return true;
    }

    /**
     * Throws unless $public may be used to seal secrets of $version: checked
     * directly when this process has the private key, otherwise against the
     * confirmation recorded by the scheduler.
     *
     * @throws SecretStoreException
     */
    public function assertSealable(int $version, string $public): void
    {
        try {
            $private = $this->privateKeys->privateKey($version);
        } catch (SecretStoreException) {
            $private = null;
        }

        if ($private !== null) {
            if (! self::matches($private, $public)) {
                throw SecretStoreException::invalidKek(sprintf('the private key v%d does not match WPUP_SECRETS_PUBLIC_KEY_V%d.', $version, $version));
            }

            return;
        }

        $confirmed = DB::table(self::TABLE)->where('version', $version)->value('public_key_sha256');

        if (! is_string($confirmed) || ! hash_equals($confirmed, hash('sha256', $public))) {
            throw SecretStoreException::kekNotConfirmed($version);
        }
    }

    /**
     * Wipes $private.
     */
    private static function matches(Secret $private, string $public): bool
    {
        try {
            return hash_equals($public, KekDecoder::publicKeyOf($private));
        } finally {
            $private->wipe();
        }
    }
}
