<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupKey;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetException;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\FlysystemTarget;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\BackupTargetType;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Site;
use Illuminate\Database\UniqueConstraintViolationException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToCheckExistence;
use Tests\Contracts\BackupTargetContract;

/*
 * The BackupTarget interface, its registry and the reference Flysystem
 * implementation (UZEROWPUP-37).
 */

function localBackupTarget(): FlysystemTarget
{
    $dir = sys_get_temp_dir().'/wpup-backup-target-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    return flysystemTargetIn($dir);
}

function flysystemTargetIn(string $dir): FlysystemTarget
{
    return new FlysystemTarget(new Filesystem(new LocalFilesystemAdapter($dir)), app(SecretRedactor::class), 1_048_576);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function linkedBackupTarget(Site $site, array $attributes): BackupTargetRecord
{
    $record = BackupTargetRecord::factory()->create($attributes);
    $site->backupTargets()->attach($record->id);

    return $record;
}

BackupTargetContract::register(fn (): BackupTarget => localBackupTarget(), 'FlysystemTarget (local)');

it('accepts only relative keys of safe segments', function (): void {
    expect(BackupKey::valid('site-1/run-2/db.sql.gz'))->toBe('site-1/run-2/db.sql.gz')
        ->and(BackupKey::validPrefix(''))->toBe('')
        ->and(BackupKey::validPrefix('site-1/'))->toBe('site-1/')
        ->and(fn () => BackupKey::valid('site-1/../x'))->toThrow(BackupTargetException::class)
        ->and(fn () => BackupKey::valid(str_repeat('a', 129)))->toThrow(BackupTargetException::class);
});

it('refuses something that is not a readable stream', function (): void {
    $broken = fopen('php://memory', 'rb');
    assert(is_resource($broken));
    fclose($broken);

    expect(fn () => localBackupTarget()->put('x/y.gz', $broken))->toThrow(BackupTargetException::class, 'flux de dades');
});

it('hides an interrupted upload and never publishes it under the final key', function (): void {
    $dir = sys_get_temp_dir().'/wpup-backup-target-'.bin2hex(random_bytes(6));
    mkdir($dir.'/x', 0700, true);
    // What a crash in the middle of writeStream() leaves behind.
    mkdir($dir.'/.wpup-tmp', 0700);
    file_put_contents($dir.'/.wpup-tmp/0123abcd', 'trunc');
    $target = flysystemTargetIn($dir);

    expect($target->list())->toBe([])
        ->and($target->exists('x/y.gz'))->toBeFalse();

    $target->put('x/y.gz', BackupTargetContract::stream('complete'));

    expect($target->checksum('x/y.gz'))->toBe(hash('sha256', 'complete'))
        ->and(array_map(static fn ($o): string => $o->key, $target->list()))->toBe(['x/y.gz']);
});

it('resolves a registered destination type and refuses unknown or inactive ones', function (): void {
    $registry = new BackupTargetRegistry;
    $target = localBackupTarget();
    $registry->register(BackupTargetType::Sftp, static fn (): BackupTarget => $target);
    $site = Site::factory()->create();
    $actor = AuditActor::system('tests');

    expect($registry->for(linkedBackupTarget($site, ['type' => BackupTargetType::Sftp]), $site, $actor))->toBe($target)
        ->and($registry->has(BackupTargetType::S3))->toBeFalse()
        ->and(fn () => $registry->for(linkedBackupTarget($site, ['type' => BackupTargetType::S3]), $site, $actor))
        ->toThrow(BackupTargetException::class, 'cap destinació de còpia registrada')
        ->and(fn () => $registry->for(linkedBackupTarget($site, ['type' => BackupTargetType::Sftp, 'active' => false]), $site, $actor))
        ->toThrow(BackupTargetException::class, 'desactivada')
        ->and(fn () => $registry->for(BackupTargetRecord::factory()->create(['type' => BackupTargetType::Sftp]), $site, $actor))
        ->toThrow(BackupTargetException::class, 'no és del lloc');
});

it('lets a site use several destinations', function (): void {
    $site = Site::factory()->create();
    $a = BackupTargetRecord::factory()->create();
    $b = BackupTargetRecord::factory()->create();

    $site->backupTargets()->attach([$a->id, $b->id]);

    expect($site->backupTargets()->pluck('backup_targets.id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all())
        ->and($a->sites()->pluck('sites.id')->all())->toBe([$site->id])
        ->and(fn () => $site->backupTargets()->attach($a->id))->toThrow(UniqueConstraintViolationException::class);
});

it('keeps stored objects private on a local disk', function (): void {
    $dir = sys_get_temp_dir().'/wpup-backup-target-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    flysystemTargetIn($dir)->put('site-1/r1/db.sql.gz', BackupTargetContract::stream('secret rows'));

    expect(fileperms($dir.'/site-1/r1/db.sql.gz') & 0777)->toBe(0600)
        ->and(fileperms($dir.'/site-1') & 0777)->toBe(0700);
});

it('masks secrets in adapter errors', function (): void {
    app(SecretRedactor::class)->register('s3cr3t-token-value');
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $filesystem->shouldReceive('fileExists')->andThrow(new UnableToCheckExistence('https://bucket/x?sig=s3cr3t-token-value'));
    $target = new FlysystemTarget($filesystem, app(SecretRedactor::class), 1024);

    expect(fn () => $target->exists('x/y.gz'))->toThrow(BackupTargetException::class)
        ->and(fn () => $target->exists('x/y.gz'))->not->toThrow(BackupTargetException::class, 's3cr3t-token-value');
});
