<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Secrets\EnvelopeSecretStore;
use App\Domain\Secrets\Kek\KekProvider;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStore;
use App\Enums\SiteDriverType;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\HostingAccount;
use App\Models\StoredSecret;
use App\Models\User;
use Tests\Support\StaticKekProvider;

const KEK_V1 = 'ce1CKPFN8+2OT6TXkqt+CjLOw/XlAp4CvkGMdf7wup8=';
const KEK_V2 = 'n4bQgYhMZ1Zf0Q5cNq3jzP0oS9Lr2ZK7mU8xVtYw1aE=';

beforeEach(function (): void {
    User::factory()->create(['unix_username' => 'admin1', 'role' => UserRole::Admin]);
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('admin1');
});

function useKeks(int $current): void
{
    app()->forgetInstance(EnvelopeSecretStore::class);
    app()->forgetInstance(SecretStore::class);
    app()->instance(KekProvider::class, new StaticKekProvider([1 => KEK_V1, 2 => KEK_V2], $current));
}

it('re-wraps every data key with the current KEK, idempotently', function (): void {
    useKeks(1);
    $ids = [];
    foreach (['a', 'b', 'c'] as $value) {
        $ids[$value] = app(SecretStore::class)->put('test', new Secret("valor-{$value}-secret"), AuditActor::system('tests'));
    }
    $ciphertexts = StoredSecret::query()->whereIn('id', $ids)->orderBy('id')->pluck('ciphertext', 'id')->all();

    useKeks(2);
    app(EnvelopeSecretStore::class)->rewrap($ids['a']); // an interrupted earlier run

    command('secrets:rekey', ['--dry-run' => true])->expectsOutputToContain('Es tornarien a xifrar 2')->assertSuccessful();
    command('secrets:rekey')->expectsConfirmation('Tornar a xifrar 2 claus de dades amb la KEK vigent?', 'yes')->assertSuccessful();
    command('secrets:rekey')->expectsOutputToContain('ja són amb la KEK vigent')->assertSuccessful();

    expect(StoredSecret::query()->whereIn('id', $ids)->pluck('kek_version')->unique()->all())->toBe([2])
        ->and(StoredSecret::query()->whereIn('id', $ids)->orderBy('id')->pluck('ciphertext', 'id')->all())->toBe($ciphertexts);

    // Only the new KEK is needed from now on.
    app()->instance(KekProvider::class, new StaticKekProvider([2 => KEK_V2], 2));
    app()->forgetInstance(EnvelopeSecretStore::class);
    app()->forgetInstance(SecretStore::class);
    expect(app(SecretStore::class)->reveal($ids['c'], AuditActor::system('tests'), 'test')->reveal())->toBe('valor-c-secret')
        ->and(AuditLogEntry::query()->where('action', 'secrets.rekey')->latest('id')->value('context'))->toEqualCanonicalizing(['rewrapped' => 2, 'pending' => 0]);
});

it('stores an SFTP password typed twice without echo', function (): void {
    $account = HostingAccount::factory()->create(['driver' => SiteDriverType::Agent]);
    $server = $account->hostingServer()->firstOrFail();

    command('secrets:set-password', ['server' => $server->name, 'account' => $account->system_user])
        ->expectsQuestion('Contrasenya SFTP', 'sftp-pass-123')
        ->expectsQuestion('Repeteix-la', 'sftp-pass-123')
        ->doesntExpectOutputToContain('sftp-pass-123')
        ->assertSuccessful();

    $ref = $account->fresh()->credential_ref ?? '';
    expect(app(SecretStore::class)->reveal($ref, AuditActor::system('tests'), 'test')->reveal())->toBe('sftp-pass-123')
        ->and(StoredSecret::query()->findOrFail($ref)->ciphertext)->not->toContain('sftp-pass-123');
});

it('refuses mismatched passwords and SSH accounts', function (): void {
    $agent = HostingAccount::factory()->create(['driver' => SiteDriverType::Agent]);
    $ssh = HostingAccount::factory()->create(['driver' => SiteDriverType::Ssh]);

    command('secrets:set-password', ['server' => $agent->hostingServer()->firstOrFail()->name, 'account' => $agent->system_user])
        ->expectsQuestion('Contrasenya SFTP', 'una')
        ->expectsQuestion('Repeteix-la', 'altra')
        ->assertFailed();
    command('secrets:set-password', ['server' => $ssh->hostingServer()->firstOrFail()->name, 'account' => $ssh->system_user])
        ->expectsOutputToContain('no cal contrasenya')
        ->assertFailed();

    expect($agent->fresh()?->credential_ref)->toBeNull();
});

it('keeps key and secret commands for admins only', function (): void {
    $account = HostingAccount::factory()->create();
    actingAsOperator('oper1');

    foreach (['ssh-key:generate', 'ssh-key:rotate', 'secrets:set-password'] as $name) {
        command($name, ['server' => $account->hostingServer()->firstOrFail()->name, 'account' => $account->system_user])
            ->expectsOutputToContain('No tens permís')
            ->assertFailed();
    }
    command('secrets:rekey')->expectsOutputToContain('No tens permís')->assertFailed();
});

it('needs the egress IP before generating keys', function (): void {
    config(['wpup.ssh.egress_ip' => null]);
    $account = HostingAccount::factory()->create();

    command('ssh-key:generate', ['server' => $account->hostingServer()->firstOrFail()->name, 'account' => $account->system_user])
        ->expectsOutputToContain('WPUP_EGRESS_IP')
        ->assertFailed();
});
