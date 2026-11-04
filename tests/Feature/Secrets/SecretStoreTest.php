<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Secrets\EnvelopeSecretStore;
use App\Domain\Secrets\Kek\ConfigPublicKeyRing;
use App\Domain\Secrets\Kek\EnvKekProvider;
use App\Domain\Secrets\Kek\KekConfirmation;
use App\Domain\Secrets\Kek\KekProvider;
use App\Domain\Secrets\Kek\SystemdCredentialKekProvider;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Secrets\SecretStore;
use App\Domain\Secrets\SecretStoreException;
use App\Enums\AuditResult;
use App\Models\AuditLogEntry;
use App\Models\StoredSecret;
use Illuminate\Support\Facades\DB;
use Tests\Support\StaticKekProvider;

// Test-only X25519 private keys: they protect nothing real.
const PRIVATE_V1 = 'ce1CKPFN8+2OT6TXkqt+CjLOw/XlAp4CvkGMdf7wup8=';
const PRIVATE_V2 = 'n4bQgYhMZ1Zf0Q5cNq3jzP0oS9Lr2ZK7mU8xVtYw1aE=';

const SSH_KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAAtest-only\n-----END OPENSSH PRIVATE KEY-----";

function store(): SecretStore
{
    return app(SecretStore::class);
}

function actor(): AuditActor
{
    return AuditActor::system('test');
}

it('round-trips a secret', function (): void {
    $id = store()->put('hosting_account.ssh_key', new Secret(SSH_KEY), actor());

    expect(store()->reveal($id, actor(), 'test')->reveal())->toBe(SSH_KEY);
});

it('never stores plain text in the database', function (): void {
    $id = store()->put('hosting_account.ssh_key', new Secret(SSH_KEY), actor());

    $row = (array) DB::table('secrets')->where('id', $id)->first();

    expect(json_encode($row, JSON_THROW_ON_ERROR))->not->toContain('OPENSSH')
        ->and($row['kek_version'])->toBe(1);
});

it('hides the cryptographic columns when the model is serialized', function (): void {
    $id = store()->put('x', new Secret('value'), actor());

    expect(StoredSecret::query()->findOrFail($id)->toArray())
        ->not->toHaveKeys(['ciphertext', 'nonce', 'wrapped_dek']);
});

it('audits every reveal with the reason and without the value', function (): void {
    $id = store()->put('hosting_account.ssh_key', new Secret(SSH_KEY), actor());
    store()->reveal($id, actor(), 'run 42: inventory');

    $entry = AuditLogEntry::query()->where('action', 'secret.reveal')->where('object_id', $id)->sole();

    expect($entry->result)->toBe(AuditResult::Success)
        ->and($entry->context)->toEqualCanonicalizing(['purpose' => 'hosting_account.ssh_key', 'reason' => 'run 42: inventory'])
        ->and(json_encode($entry->toArray(), JSON_THROW_ON_ERROR))->not->toContain('OPENSSH');
});

it('replaces a secret value', function (): void {
    $id = store()->put('x', new Secret('old'), actor());
    store()->replace($id, new Secret('new'), actor());

    expect(store()->reveal($id, actor(), 'test')->reveal())->toBe('new');
});

it('fails with a different KEK and audits the failure', function (): void {
    $id = store()->put('x', new Secret(SSH_KEY), actor());
    $other = new EnvKekProvider(base64_encode(random_bytes(32)), 'testing');
    $otherKek = new EnvelopeSecretStore($other, $other, app(AuditLogger::class), app(SecretRedactor::class), new KekConfirmation($other, $other));

    expect(fn () => $otherKek->reveal($id, actor(), 'test'))
        ->toThrow(SecretStoreException::class, 'could not be decrypted')
        ->and(AuditLogEntry::query()->where('object_id', $id)->where('result', AuditResult::Failure->value)->exists())->toBeTrue();
});

it('fails when the ciphertext is moved to another row', function (): void {
    $a = store()->put('x', new Secret('secret A'), actor());
    $b = store()->put('x', new Secret('secret B'), actor());

    $source = (array) DB::table('secrets')->where('id', $a)->first();
    DB::table('secrets')->where('id', $b)->update([
        'ciphertext' => $source['ciphertext'],
        'nonce' => $source['nonce'],
        'wrapped_dek' => $source['wrapped_dek'],
    ]);

    store()->reveal($b, actor(), 'test');
})->throws(SecretStoreException::class, 'could not be decrypted');

it('fails when the ciphertext is tampered with', function (): void {
    $id = store()->put('x', new Secret('value'), actor());
    $stored = StoredSecret::query()->findOrFail($id);
    $raw = base64_decode($stored->ciphertext, true);
    expect($raw)->toBeString();
    $tampered = (string) $raw;
    $tampered[0] = chr(ord($tampered[0]) ^ 1);
    DB::table('secrets')->where('id', $id)->update(['ciphertext' => base64_encode($tampered)]);

    store()->reveal($id, actor(), 'test');
})->throws(SecretStoreException::class, 'could not be decrypted');

it('reports a missing secret', function (): void {
    store()->reveal('0192f3c4-0000-7000-8000-000000000000', actor(), 'test');
})->throws(SecretStoreException::class, 'does not exist');

it('reads the private key from the systemd credentials directory', function (): void {
    $dir = sys_get_temp_dir().'/wpup-creds-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    $key = random_bytes(32);
    file_put_contents($dir.'/wpup-kek-v3', base64_encode($key)."\n");

    try {
        expect((new SystemdCredentialKekProvider($dir, 'wpup-kek-v'))->privateKey(3)->reveal())->toBe($key);
    } finally {
        unlink($dir.'/wpup-kek-v3');
        rmdir($dir);
    }
});

it('explains that only the workers have the private key', function (): void {
    (new SystemdCredentialKekProvider(null, 'wpup-kek-v'))->privateKey(1);
})->throws(SecretStoreException::class, 'Only the wpup worker services can read secrets');

/*
 * ADR-0011: a process with only the public key (the CLI and the web in
 * production) can store secrets but never read them back.
 */
describe('without the private key', function (): void {
    beforeEach(function (): void {
        $keys = new StaticKekProvider([1 => PRIVATE_V1], 1, withPrivateKeys: false);
        $keys->bind();
        $keys->confirm();
    });

    it('stores and replaces secrets with the public key alone', function (): void {
        $id = store()->put('x', new Secret(SSH_KEY), actor());
        store()->replace($id, new Secret('new value'), actor());

        (new StaticKekProvider([1 => PRIVATE_V1], 1))->bind();

        expect(store()->reveal($id, actor(), 'test')->reveal())->toBe('new value');
    });

    it('cannot reveal a secret, and the attempt is audited', function (): void {
        $id = store()->put('x', new Secret(SSH_KEY), actor());

        expect(fn () => store()->reveal($id, actor(), 'test'))->toThrow(SecretStoreException::class, 'no private key')
            ->and(AuditLogEntry::query()->where('action', 'secret.reveal')->where('object_id', $id)->value('result'))->toBe(AuditResult::Failure);
    });

    it('cannot re-seal a secret', function (): void {
        $id = store()->put('x', new Secret(SSH_KEY), actor());
        (new StaticKekProvider([1 => PRIVATE_V1, 2 => PRIVATE_V2], 2, withPrivateKeys: false))->bind();

        app(EnvelopeSecretStore::class)->rewrap($id);
    })->throws(SecretStoreException::class, 'no private key');
});

it('fails when the sealed data key is tampered with', function (): void {
    $id = store()->put('x', new Secret('value'), actor());
    $raw = (string) base64_decode(StoredSecret::query()->findOrFail($id)->wrapped_dek, true);
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    DB::table('secrets')->where('id', $id)->update(['wrapped_dek' => base64_encode($raw)]);

    store()->reveal($id, actor(), 'test');
})->throws(SecretStoreException::class, 'could not be decrypted');

it('fails when a row is relabelled with another KEK version', function (): void {
    // Same key under both versions, so only the version bound in the envelope tells them apart.
    (new StaticKekProvider([1 => PRIVATE_V1, 2 => PRIVATE_V1], 1))->bind();
    $id = store()->put('x', new Secret('value'), actor());
    DB::table('secrets')->where('id', $id)->update(['kek_version' => 2]);

    store()->reveal($id, actor(), 'test');
})->throws(SecretStoreException::class, 'could not be decrypted');

/**
 * A store whose public key ring holds a key nobody has the private half of
 * (a wrong WPUP_SECRETS_PUBLIC_KEY_V1 pasted into .env).
 */
function storeWithWrongPublicKey(KekProvider $private): EnvelopeSecretStore
{
    $wrongPublic = new ConfigPublicKeyRing([1 => base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair()))], 1);

    return new EnvelopeSecretStore($wrongPublic, $private, app(AuditLogger::class), app(SecretRedactor::class), new KekConfirmation($wrongPublic, $private));
}

it('refuses to seal with a public key that does not match the private key', function (): void {
    storeWithWrongPublicKey(new EnvKekProvider(PRIVATE_V1, 'testing'))->put('x', new Secret('value'), actor());
})->throws(SecretStoreException::class, 'does not match');

it('refuses a private key that does not match the configured public key on reveal', function (): void {
    $id = store()->put('x', new Secret('value'), actor());

    storeWithWrongPublicKey(new EnvKekProvider(PRIVATE_V1, 'testing'))->reveal($id, actor(), 'test');
})->throws(SecretStoreException::class, 'does not match');

/*
 * The CLI and the web cannot check the key pair themselves: they seal only with
 * a public key that the scheduler has confirmed against its private half.
 */
describe('public key confirmation', function (): void {
    it('refuses to seal until the scheduler has confirmed the public key', function (): void {
        (new StaticKekProvider([1 => PRIVATE_V1], 1, withPrivateKeys: false))->bind();

        expect(fn () => store()->put('x', new Secret('value'), actor()))->toThrow(SecretStoreException::class, 'has not been confirmed')
            ->and(StoredSecret::query()->count())->toBe(0);
    });

    it('withdraws the confirmation when the scheduler finds a mismatched key pair', function (): void {
        $keys = new StaticKekProvider([1 => PRIVATE_V1], 1, withPrivateKeys: false);
        $keys->bind();
        $keys->confirm();
        $wrongPublic = new ConfigPublicKeyRing([1 => base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair()))], 1);

        expect((new KekConfirmation($wrongPublic, new EnvKekProvider(PRIVATE_V1, 'testing')))->confirm())->toBeFalse()
            ->and(DB::table('kek_confirmations')->count())->toBe(0)
            ->and(fn () => store()->put('x', new Secret('value'), actor()))->toThrow(SecretStoreException::class, 'has not been confirmed');
    });

    it('refuses a public key other than the one confirmed', function (): void {
        (new StaticKekProvider([1 => PRIVATE_V1], 1, withPrivateKeys: false))->confirm();
        $wrongPublic = new ConfigPublicKeyRing([1 => base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair()))], 1);
        $publicOnly = new StaticKekProvider([1 => PRIVATE_V1], 1, withPrivateKeys: false);
        $store = new EnvelopeSecretStore($wrongPublic, $publicOnly, app(AuditLogger::class), app(SecretRedactor::class), new KekConfirmation($wrongPublic, $publicOnly));

        $store->put('x', new Secret('value'), actor());
    })->throws(SecretStoreException::class, 'has not been confirmed');

    it('needs a new confirmation for a new KEK version', function (): void {
        (new StaticKekProvider([1 => PRIVATE_V1], 1, withPrivateKeys: false))->confirm();
        (new StaticKekProvider([1 => PRIVATE_V1, 2 => PRIVATE_V2], 2, withPrivateKeys: false))->bind();

        store()->put('x', new Secret('value'), actor());
    })->throws(SecretStoreException::class, 'WPUP_SECRETS_PUBLIC_KEY_V2 has not been confirmed');
});

it('explains a missing public key version', function (): void {
    (new ConfigPublicKeyRing([], 4))->publicKey(4);
})->throws(SecretStoreException::class, 'WPUP_SECRETS_PUBLIC_KEY_V4');
