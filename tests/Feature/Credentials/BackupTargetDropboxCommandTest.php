<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\Dropbox\DropboxSettings;
use App\Domain\Backups\Dropbox\DropboxSetupException;
use App\Domain\Backups\Dropbox\DropboxTargetSetup;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStore;
use App\Enums\BackupTargetType;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\StoredSecret;
use App\Models\User;
use Illuminate\Testing\PendingCommand;
use Tests\Support\FakeDropboxApi;

const DBX_KEY = 'app-key-AAA111';
const DBX_SECRET = 'app-secret-BBB222';
const DBX_TOKEN = 'refresh-token-CCC333';

beforeEach(function (): void {
    User::factory()->create(['unix_username' => 'admin1', 'role' => UserRole::Admin]);
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('admin1');

    // The command checks the credential against a fake Dropbox that knows these values.
    $api = FakeDropboxApi::inTemp();
    $api->appKey = DBX_KEY;
    $api->appSecret = DBX_SECRET;
    $api->refreshToken = DBX_TOKEN;
    app()->instance(DropboxSettings::class, FakeDropboxApi::settings());
    app()->instance('wpup.dropbox.handler', $api);
});

function dropboxApi(): FakeDropboxApi
{
    $api = app('wpup.dropbox.handler');

    return $api instanceof FakeDropboxApi ? $api : throw new LogicException('No fake Dropbox bound.');
}

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

function dropboxTargets(): int
{
    return BackupTarget::query()->where('type', BackupTargetType::Dropbox)->count();
}

it('creates the destination and stores the credential encrypted', function (): void {
    typeDropbox('dbx', 'wpup/copies')->expectsOutputToContain('creada')->assertSuccessful();

    $target = BackupTarget::query()->where('name', 'dbx')->firstOrFail();
    expect($target->type)->toBe(BackupTargetType::Dropbox)
        ->and($target->config)->toBe(['root' => 'wpup/copies'])
        ->and($target->active)->toBeTrue()
        ->and(json_decode(revealedDropbox($target), true))->toBe(['app_key' => DBX_KEY, 'app_secret' => DBX_SECRET, 'refresh_token' => DBX_TOKEN])
        ->and(StoredSecret::query()->findOrFail($target->credential_ref)->ciphertext)->not->toContain(DBX_SECRET)
        ->and(StoredSecret::query()->findOrFail($target->credential_ref)->purpose)->toBe('dropbox-credential')
        ->and(dropboxApi()->calls)->toBe(['oauth2/token']);

    $audit = AuditLogEntry::query()->where('action', 'backup_target.dropbox.set')->firstOrFail();
    expect($audit->context)->toMatchArray(['name' => 'dbx', 'root' => 'wpup/copies', 'previous_root' => null, 'created' => true]);
});

it('stores the trimmed values', function (): void {
    typeDropbox('dbx', 'uno', '  '.DBX_KEY."\n", DBX_SECRET.' ', DBX_TOKEN)->assertSuccessful();

    expect(json_decode(revealedDropbox(BackupTarget::query()->where('name', 'dbx')->firstOrFail()), true))
        ->toBe(['app_key' => DBX_KEY, 'app_secret' => DBX_SECRET, 'refresh_token' => DBX_TOKEN]);
});

it('replaces the secret when run again, with the same root', function (): void {
    typeDropbox('dbx', 'uno')->assertSuccessful();
    $ref = BackupTarget::query()->where('name', 'dbx')->firstOrFail()->credential_ref;
    dropboxApi()->appSecret = 's2';
    dropboxApi()->refreshToken = 't2';

    typeDropbox('dbx', 'uno', DBX_KEY, 's2', 't2')->expectsOutputToContain('actualitzada')->assertSuccessful();

    $target = BackupTarget::query()->where('name', 'dbx')->firstOrFail();
    expect(dropboxTargets())->toBe(1)
        ->and($target->credential_ref)->toBe($ref)
        ->and(json_decode(revealedDropbox($target), true))->toBe(['app_key' => DBX_KEY, 'app_secret' => 's2', 'refresh_token' => 't2'])
        ->and(AuditLogEntry::query()->where('action', 'backup_target.dropbox.set')->count())->toBe(2);
});

it('changes the root of a destination that holds no copies and audits the previous one', function (): void {
    typeDropbox('dbx', 'uno')->assertSuccessful();
    typeDropbox('dbx', 'dos')->assertSuccessful();

    expect(BackupTarget::query()->where('name', 'dbx')->firstOrFail()->config)->toBe(['root' => 'dos'])
        ->and(AuditLogEntry::query()->where('action', 'backup_target.dropbox.set')->latest('id')->firstOrFail()->context)
        ->toMatchArray(['root' => 'dos', 'previous_root' => 'uno', 'created' => false]);
});

it('refuses to change the root while the destination holds copies', function (): void {
    typeDropbox('dbx', 'uno')->assertSuccessful();
    $target = BackupTarget::query()->where('name', 'dbx')->firstOrFail();
    $backup = Backup::factory()->create(['backup_target_id' => $target->id]);
    $calls = count(dropboxApi()->calls);

    command('backup-target:dropbox', ['name' => 'dbx', '--root' => 'dos'])
        ->expectsOutputToContain('altre nom')
        ->assertFailed();
    command('backup-target:dropbox', ['name' => 'dbx', '--root' => 'dos', '--dry-run' => true])
        ->expectsOutputToContain('altre nom')
        ->assertFailed();

    expect($target->fresh()?->config)->toBe(['root' => 'uno'])
        ->and(dropboxApi()->calls)->toHaveCount($calls);

    // The same root (a rotation) is fine, and so is a change once the copies are gone.
    typeDropbox('dbx', 'uno')->assertSuccessful();
    $backup->forceFill(['deleted_at' => now()])->save();
    typeDropbox('dbx', 'dos')->assertSuccessful();
});

it('writes nothing and contacts nothing on dry-run', function (): void {
    command('backup-target:dropbox', ['name' => 'dbx', '--root' => 'uno', '--dry-run' => true])
        ->expectsOutputToContain('[dry-run]')
        ->assertSuccessful();

    expect(BackupTarget::query()->where('name', 'dbx')->exists())->toBeFalse()
        ->and(StoredSecret::query()->count())->toBe(0)
        ->and(dropboxApi()->calls)->toBe([])
        ->and(AuditLogEntry::query()->where('action', 'backup_target.dropbox.set')->exists())->toBeFalse();
});

it('rejects an invalid root', function (string $root): void {
    command('backup-target:dropbox', ['name' => 'dbx', '--root' => $root])
        ->expectsOutputToContain('--root')
        ->assertFailed();

    expect(dropboxTargets())->toBe(0);
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

    expect(dropboxTargets())->toBe(0)
        ->and(StoredSecret::query()->count())->toBe(0);
});

it('stores nothing when Dropbox rejects the credential', function (): void {
    typeDropbox('dbx', 'uno', DBX_KEY, DBX_SECRET, 'refresh-token-DDD444')
        ->expectsOutputToContain('invalid_grant')
        ->doesntExpectOutputToContain('refresh-token-DDD444')
        ->assertFailed();

    expect(dropboxTargets())->toBe(0)
        ->and(StoredSecret::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->where('action', 'backup_target.dropbox.set')->exists())->toBeFalse();
});

it('keeps the old credential when the new one is rejected', function (): void {
    typeDropbox('dbx', 'uno')->assertSuccessful();

    typeDropbox('dbx', 'uno', DBX_KEY, DBX_SECRET, 'refresh-token-DDD444')->assertFailed();

    expect(json_decode(revealedDropbox(BackupTarget::query()->where('name', 'dbx')->firstOrFail()), true))
        ->toMatchArray(['refresh_token' => DBX_TOKEN]);
});

it('refuses text that is not valid UTF-8', function (): void {
    typeDropbox('dbx', 'uno', DBX_KEY, DBX_SECRET, "tok\xB1en")
        ->expectsOutputToContain('UTF-8')
        ->assertFailed();

    expect(dropboxTargets())->toBe(0)
        ->and(dropboxApi()->calls)->toBe([]);
});

it('refuses a name that belongs to another type', function (): void {
    BackupTarget::factory()->create(['name' => 'altre-tipus', 'type' => BackupTargetType::SiteLocal]);

    command('backup-target:dropbox', ['name' => 'altre-tipus', '--root' => 'uno'])
        ->expectsOutputToContain('altre tipus')
        ->assertFailed();

    expect(BackupTarget::query()->where('name', 'altre-tipus')->firstOrFail()->type)->toBe(BackupTargetType::SiteLocal);
});

it('stores a new secret when the referenced one is missing', function (): void {
    typeDropbox('dbx', 'uno')->assertSuccessful();
    $target = BackupTarget::query()->where('name', 'dbx')->firstOrFail();
    $old = $target->credential_ref;
    StoredSecret::query()->whereKey($old)->delete();

    typeDropbox('dbx', 'uno')->assertSuccessful();

    $target->refresh();
    expect($target->credential_ref)->not->toBe($old)
        ->and(json_decode(revealedDropbox($target), true))->toMatchArray(['refresh_token' => DBX_TOKEN])
        ->and(AuditLogEntry::query()->where('action', 'backup_target.dropbox.set')->latest('id')->firstOrFail()->context)->toMatchArray(['secret_recreated' => true]);
});

it('refuses to touch a secret stored for something else', function (): void {
    typeDropbox('dbx', 'uno')->assertSuccessful();
    $target = BackupTarget::query()->where('name', 'dbx')->firstOrFail();
    $other = app(SecretStore::class)->put('sftp-password', new Secret('una-contrasenya'), AuditActor::system('tests'));
    $target->forceFill(['credential_ref' => $other])->save();

    command('backup-target:dropbox', ['name' => 'dbx', '--root' => 'uno'])
        ->expectsOutputToContain('no és d\'una destinació de Dropbox')
        ->assertFailed();

    expect(app(SecretStore::class)->reveal($other, AuditActor::system('tests'), 'test')->reveal())->toBe('una-contrasenya');
});

it('refuses to ask for credentials without an interactive terminal', function (): void {
    command('backup-target:dropbox', ['name' => 'dbx', '--root' => 'uno', '--no-interaction' => true])
        ->expectsOutputToContain('terminal interactiu')
        ->assertFailed();

    expect(dropboxTargets())->toBe(0);
});

it('is for admins only', function (): void {
    actingAsOperator('oper1');

    command('backup-target:dropbox', ['name' => 'dbx', '--root' => 'uno'])
        ->expectsOutputToContain('No tens permís')
        ->assertFailed();

    expect(dropboxTargets())->toBe(0);
});

it('never leaves the values in the audit log', function (): void {
    typeDropbox('dbx', 'uno')->assertSuccessful();

    $dump = json_encode(AuditLogEntry::query()->get()->toArray(), JSON_THROW_ON_ERROR);
    expect(str_contains($dump, DBX_SECRET) || str_contains($dump, DBX_TOKEN) || str_contains($dump, DBX_KEY))->toBeFalse();
});

it('sets a destination up through the domain service', function (): void {
    $setup = app(DropboxTargetSetup::class);
    // The root is checked before anything is stored.
    expect(fn () => $setup->apply('dbx', 'Mal', ['app_key' => DBX_KEY, 'app_secret' => DBX_SECRET, 'refresh_token' => DBX_TOKEN], AuditActor::system('tests')))
        ->toThrow(DropboxSetupException::class);

    $result = $setup->apply('dbx', 'ok', ['app_key' => DBX_KEY, 'app_secret' => DBX_SECRET, 'refresh_token' => DBX_TOKEN], AuditActor::system('tests'));

    expect($result->created)->toBeTrue()
        ->and($result->previousRoot)->toBeNull()
        ->and(StoredSecret::query()->where('purpose', 'dropbox-credential')->count())->toBe(1)
        ->and(dropboxApi()->calls)->toBe(['oauth2/token']);
});
