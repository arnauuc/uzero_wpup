<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetException;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\Dropbox\DropboxAdapter;
use App\Domain\Backups\Dropbox\DropboxClient;
use App\Domain\Backups\Dropbox\DropboxSettings;
use App\Domain\Backups\Dropbox\DropboxTargetFactory;
use App\Domain\Backups\Dropbox\DropboxTokenProvider;
use App\Domain\Backups\FlysystemTarget;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Secrets\SecretStore;
use App\Enums\BackupTargetType;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Site;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use League\Flysystem\Config;
use League\Flysystem\UnableToCreateDirectory;
use Tests\Contracts\BackupTargetContract;
use Tests\Support\FakeDropboxApi;

/*
 * The Dropbox destination (UZEROWPUP-107): DropboxAdapter under
 * FlysystemTarget, built from a backup_targets row by DropboxTargetFactory,
 * against the in-process fake of the API.
 */

function dropboxTargetFactory(FakeDropboxApi $fake): DropboxTargetFactory
{
    return new DropboxTargetFactory(app(SecretStore::class), app(SecretRedactor::class), FakeDropboxApi::settings(), 1_048_576, $fake(...));
}

/**
 * @param  array<string, mixed>  $config
 */
function dropboxRecord(FakeDropboxApi $fake, array $config = ['root' => 'wpup/copies'], ?string $refreshToken = null): BackupTargetRecord
{
    $json = (string) json_encode(['app_key' => $fake->appKey, 'app_secret' => $fake->appSecret, 'refresh_token' => $refreshToken ?? $fake->refreshToken]);
    $ref = app(SecretStore::class)->put('backup_target.dropbox', new Secret($json), AuditActor::system('tests'));

    return BackupTargetRecord::factory()->create(['type' => BackupTargetType::Dropbox, 'config' => $config, 'credential_ref' => $ref]);
}

function dropboxTarget(?FakeDropboxApi $fake = null): FlysystemTarget
{
    $fake ??= FakeDropboxApi::inTemp();

    return dropboxTargetFactory($fake)->make(dropboxRecord($fake), AuditActor::system('tests'));
}

BackupTargetContract::register(fn (): BackupTarget => dropboxTarget(), 'Dropbox (fake API)');

it('keeps everything inside the configured folder of the App folder', function (): void {
    $fake = FakeDropboxApi::inTemp();
    dropboxTarget($fake)->put('site-1/r1/db.sql.gz', BackupTargetContract::stream('rows'));

    expect(file_get_contents($fake->dir.'/files/wpup/copies/site-1/r1/db.sql.gz'))->toBe('rows')
        ->and(array_unique(array_map(static fn (string $arg): bool => str_contains($arg, '"/wpup/copies/'), $fake->headerArgs)))->toBe([true]);
});

it('refuses a destination without a valid root or credential', function (array $attributes, string $message): void {
    /** @var array<string, mixed> $attributes */
    $fake = FakeDropboxApi::inTemp();
    $record = dropboxRecord($fake);
    $record->update($attributes);

    expect(fn () => dropboxTargetFactory($fake)->make($record, AuditActor::system('tests')))->toThrow(BackupTargetException::class, $message);
})->with([
    'no root' => [['config' => []], 'config.root'],
    'absolute root' => [['config' => ['root' => '/wpup']], 'config.root'],
    'escaping root' => [['config' => ['root' => 'wpup/../x']], 'config.root'],
    'trailing newline' => [['config' => ['root' => "wpup\n"]], 'config.root'],
    'capital letters' => [['config' => ['root' => 'WPUP']], 'config.root'],
    'no credential' => [['credential_ref' => null], 'credential_ref'],
]);

it('refuses a malformed credential without quoting it', function (): void {
    $fake = FakeDropboxApi::inTemp();
    $record = dropboxRecord($fake);
    $record->update(['credential_ref' => app(SecretStore::class)->put('backup_target.dropbox', new Secret('{"app_key":"k","app_secret":"half-a-s3cr3t"}'), AuditActor::system('tests'))]);

    expect(fn () => dropboxTargetFactory($fake)->make($record, AuditActor::system('tests')))
        ->toThrow(fn (BackupTargetException $e) => expect($e->getMessage())->toContain('refresh_token')->and(str_contains($e->getMessage(), 'half-a-s3cr3t'))->toBeFalse());
});

it('turns API failures into BackupTargetException without secrets', function (): void {
    $fake = FakeDropboxApi::inTemp();
    $target = dropboxTargetFactory($fake)->make(dropboxRecord($fake, refreshToken: 'revoked-'.bin2hex(random_bytes(8))), AuditActor::system('tests'));

    expect(fn () => $target->exists('x/y.gz'))
        ->toThrow(fn (BackupTargetException $e) => expect($e->getMessage())->toContain('invalid_grant')
            ->and(str_contains($e->getMessage(), $fake->appSecret) || str_contains($e->getMessage(), 'revoked-'))->toBeFalse());
});

it('is resolved by the registry for a dropbox row', function (): void {
    $fake = FakeDropboxApi::inTemp();
    app()->instance(DropboxTargetFactory::class, dropboxTargetFactory($fake));
    $site = Site::factory()->create();
    $record = dropboxRecord($fake);
    $site->backupTargets()->attach($record->id);

    $target = app(BackupTargetRegistry::class)->for($record, $site, AuditActor::system('tests'));
    $target->put('site-1/r1/files.tar.gz', BackupTargetContract::stream('files'));

    expect($target)->toBeInstanceOf(FlysystemTarget::class)
        ->and(app(DropboxSettings::class)->chunkBytes % DropboxSettings::CHUNK_UNIT_BYTES)->toBe(0)
        ->and($target->checksum('site-1/r1/files.tar.gz'))->toBe(hash('sha256', 'files'));
});

function dropboxAdapter(FakeDropboxApi $fake): DropboxAdapter
{
    $http = new Client(['handler' => HandlerStack::create($fake(...))]);
    $redactor = app(SecretRedactor::class);
    $settings = FakeDropboxApi::settings();

    return new DropboxAdapter(new DropboxClient($http, new DropboxTokenProvider($http, $fake->credential(), $redactor, $settings), $redactor, $settings), 'wpup');
}

it('creates folders idempotently but not over a file', function (): void {
    $fake = FakeDropboxApi::inTemp();
    $adapter = dropboxAdapter($fake);
    $adapter->write('file.gz', 'x', new Config);

    $adapter->createDirectory('d', new Config);
    $adapter->createDirectory('d', new Config);

    expect($adapter->directoryExists('d'))->toBeTrue()
        ->and(fn () => $adapter->createDirectory('file.gz', new Config))->toThrow(UnableToCreateDirectory::class, 'conflict/file');
});

it('replaces the destination of a move or copy, like other adapters', function (): void {
    $adapter = dropboxAdapter(FakeDropboxApi::inTemp());
    $adapter->write('a.gz', 'new', new Config);
    $adapter->write('b.gz', 'old', new Config);
    $adapter->write('c.gz', 'old', new Config);

    $adapter->copy('a.gz', 'c.gz', new Config);
    $adapter->move('a.gz', 'b.gz', new Config);

    expect($adapter->read('b.gz'))->toBe('new')
        ->and($adapter->read('c.gz'))->toBe('new')
        ->and($adapter->fileExists('a.gz'))->toBeFalse();
});

it('refuses capital letters and lists keys in lowercase', function (): void {
    $fake = FakeDropboxApi::inTemp();
    $target = dropboxTarget($fake);
    // Something put there by hand: Dropbox would show it as "Site-1/R1".
    mkdir($fake->dir.'/files/wpup/copies/Site-1/R1', 0700, true);
    file_put_contents($fake->dir.'/files/wpup/copies/Site-1/R1/db.sql.gz', 'x');

    expect(fn () => $target->put('Site-1/r2/db.sql.gz', BackupTargetContract::stream('x')))->toThrow(BackupTargetException::class, 'majúscules')
        ->and(array_map(static fn ($o): string => $o->key, $target->list()))->toBe(['site-1/r1/db.sql.gz']);
});
