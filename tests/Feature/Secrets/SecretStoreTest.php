<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Secrets\EnvelopeSecretStore;
use App\Domain\Secrets\Kek\EnvKekProvider;
use App\Domain\Secrets\Kek\SystemdCredentialKekProvider;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Secrets\SecretStore;
use App\Domain\Secrets\SecretStoreException;
use App\Enums\AuditResult;
use App\Models\AuditLogEntry;
use App\Models\StoredSecret;
use Illuminate\Support\Facades\DB;

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
        ->not->toHaveKeys(['ciphertext', 'nonce', 'wrapped_dek', 'dek_nonce']);
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
    $otherKek = new EnvelopeSecretStore(new EnvKekProvider(base64_encode(random_bytes(32)), 'testing'), app(AuditLogger::class), app(SecretRedactor::class));

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
        'dek_nonce' => $source['dek_nonce'],
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

it('reads the KEK from the systemd credentials directory', function (): void {
    $dir = sys_get_temp_dir().'/wpup-creds-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    $key = random_bytes(32);
    file_put_contents($dir.'/wpup-kek-v3', base64_encode($key)."\n");

    try {
        $provider = new SystemdCredentialKekProvider($dir, 'wpup-kek-v', 3);

        expect($provider->currentVersion())->toBe(3)
            ->and($provider->key(3)->reveal())->toBe($key);
    } finally {
        unlink($dir.'/wpup-kek-v3');
        rmdir($dir);
    }
});

it('explains when the systemd credential is missing', function (): void {
    (new SystemdCredentialKekProvider(null, 'wpup-kek-v', 1))->key(1);
})->throws(SecretStoreException::class, 'CREDENTIALS_DIRECTORY');
