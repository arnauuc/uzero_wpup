<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Secrets\SecretStore;
use App\Enums\BackupTargetType;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\BackupTarget;
use App\Models\StoredSecret;
use App\Models\User;
use Illuminate\Testing\PendingCommand;

const DBX_KEY = 'app-key-AAA111';
const DBX_SECRET = 'app-secret-BBB222';
const DBX_TOKEN = 'refresh-token-CCC333';

beforeEach(function (): void {
    User::factory()->create(['unix_username' => 'admin1', 'role' => UserRole::Admin]);
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('admin1');
});

function typeDropbox(string $name, string $root, string $key = DBX_KEY, string $secret = DBX_SECRET, string $token = DBX_TOKEN): PendingCommand
{
    return command('backup-target:dropbox', ['name' => $name, '--root' => $root])
        ->expectsQuestion('App key', $key)
        ->expectsQuestion('App secret', $secret)
        ->expectsQuestion('Refresh token', $token)
        ->doesntExpectOutputToContain(DBX_KEY)
        ->doesntExpectOutputToContain(DBX_SECRET)
        ->doesntExpectOutputToContain(DBX_TOKEN);
}

function revealedDropbox(BackupTarget $target): string
{
    return app(SecretStore::class)->reveal($target->credential_ref ?? '', AuditActor::system('tests'), 'test')->reveal();
}

it('creates the destination and stores the credential encrypted', function (): void {
    typeDropbox('dbx', 'wpup/copies')->expectsOutputToContain('creada')->assertSuccessful();

    $target = BackupTarget::query()->where('name', 'dbx')->firstOrFail();
    expect($target->type)->toBe(BackupTargetType::Dropbox)
        ->and($target->config)->toBe(['root' => 'wpup/copies'])
        ->and($target->active)->toBeTrue()
        ->and(json_decode(revealedDropbox($target), true))->toBe(['app_key' => DBX_KEY, 'app_secret' => DBX_SECRET, 'refresh_token' => DBX_TOKEN])
        ->and(StoredSecret::query()->findOrFail($target->credential_ref)->ciphertext)->not->toContain(DBX_SECRET)
        ->and(AuditLogEntry::query()->where('action', 'backup_target.dropbox.set')->count())->toBe(1);
});

it('replaces the secret and root when run again', function (): void {
    typeDropbox('dbx', 'uno')->assertSuccessful();
    $ref = BackupTarget::query()->where('name', 'dbx')->firstOrFail()->credential_ref;

    typeDropbox('dbx', 'dos', 'k2', 's2', 't2')->expectsOutputToContain('actualitzada')->assertSuccessful();

    $target = BackupTarget::query()->where('name', 'dbx')->firstOrFail();
    expect(BackupTarget::query()->where('type', BackupTargetType::Dropbox)->count())->toBe(1)
        ->and($target->credential_ref)->toBe($ref)
        ->and($target->config)->toBe(['root' => 'dos'])
        ->and(json_decode(revealedDropbox($target), true))->toBe(['app_key' => 'k2', 'app_secret' => 's2', 'refresh_token' => 't2'])
        ->and(AuditLogEntry::query()->where('action', 'backup_target.dropbox.set')->count())->toBe(2);
});

it('writes nothing and asks nothing on dry-run', function (): void {
    command('backup-target:dropbox', ['name' => 'dbx', '--root' => 'uno', '--dry-run' => true])
        ->expectsOutputToContain('[dry-run]')
        ->assertSuccessful();

    expect(BackupTarget::query()->where('name', 'dbx')->exists())->toBeFalse()
        ->and(StoredSecret::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->where('action', 'backup_target.dropbox.set')->exists())->toBeFalse();
});

it('rejects an invalid root', function (string $root): void {
    command('backup-target:dropbox', ['name' => 'dbx', '--root' => $root])
        ->expectsOutputToContain('--root')
        ->assertFailed();

    expect(BackupTarget::query()->where('type', BackupTargetType::Dropbox)->count())->toBe(0);
})->with(['../fora', '/absolut', 'Majuscules', 'a//b', '']);

it('rejects an invalid name', function (): void {
    command('backup-target:dropbox', ['name' => 'no vàlid', '--root' => 'uno'])->expectsOutputToContain('El nom')->assertFailed();
});

it('rejects an invalid credential without echoing it', function (): void {
    typeDropbox('dbx', 'uno', DBX_KEY, 'te espais '.DBX_SECRET, DBX_TOKEN)
        ->expectsOutputToContain('app_secret')
        ->assertFailed();
    typeDropbox('dbx', 'uno', DBX_KEY, DBX_SECRET, '')
        ->expectsOutputToContain('refresh_token')
        ->assertFailed();

    expect(BackupTarget::query()->where('type', BackupTargetType::Dropbox)->count())->toBe(0)
        ->and(StoredSecret::query()->count())->toBe(0);
});

it('refuses a name that belongs to another type', function (): void {
    BackupTarget::factory()->create(['name' => 'altre-tipus', 'type' => BackupTargetType::SiteLocal]);

    command('backup-target:dropbox', ['name' => 'altre-tipus', '--root' => 'uno'])
        ->expectsOutputToContain('altre tipus')
        ->assertFailed();

    expect(BackupTarget::query()->where('name', 'altre-tipus')->firstOrFail()->type)->toBe(BackupTargetType::SiteLocal);
});

it('is for admins only', function (): void {
    actingAsOperator('oper1');

    command('backup-target:dropbox', ['name' => 'dbx', '--root' => 'uno'])
        ->expectsOutputToContain('No tens permís')
        ->assertFailed();

    expect(BackupTarget::query()->where('type', BackupTargetType::Dropbox)->count())->toBe(0);
});

it('never leaves the values in the audit log', function (): void {
    typeDropbox('dbx', 'uno')->assertSuccessful();

    $dump = json_encode(AuditLogEntry::query()->get()->toArray(), JSON_THROW_ON_ERROR);
    expect(str_contains($dump, DBX_SECRET) || str_contains($dump, DBX_TOKEN) || str_contains($dump, DBX_KEY))->toBeFalse();
});
