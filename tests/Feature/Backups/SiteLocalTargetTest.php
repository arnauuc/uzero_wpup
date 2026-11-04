<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupTargetException;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Data\WorkFile;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\BackupTargetType;
use App\Enums\UserRole;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Site;
use App\Models\User;
use Tests\Contracts\BackupTargetContract;
use Tests\Support\FakeDriver;

/*
 * SiteLocalTarget over FakeDriver (UZEROWPUP-40). The contract suite runs
 * against the Docker test sites (tests/Integration/Backups/SiteLocalTargetTest.php):
 * FakeDriver keeps files in memory and cannot pass its bounded-memory test.
 */

function fakeLocalTarget(FakeDriver $driver): SiteLocalTarget
{
    return new SiteLocalTarget($driver, app(SecretRedactor::class), 'restore-points', 1024);
}

it('stores objects under its root in the work area, and lists only those', function (): void {
    $driver = new FakeDriver;
    $driver->files['unrelated.gz'] = 'x';
    $driver->files['restore-points/.hidden'] = 'not a key';
    $target = fakeLocalTarget($driver);

    $stored = $target->put('site-1/r1/db.sql.gz', BackupTargetContract::stream('dump'));

    expect($stored->sizeBytes)->toBe(4)
        ->and($driver->files['restore-points/site-1/r1/db.sql.gz'])->toBe('dump')
        ->and(array_keys($driver->files))->toBe(['unrelated.gz', 'restore-points/.hidden', 'restore-points/site-1/r1/db.sql.gz'])
        ->and(array_map(static fn ($o): string => $o->key, $target->list()))->toBe(['site-1/r1/db.sql.gz'])
        ->and($target->checksum('site-1/r1/db.sql.gz'))->toBe(hash('sha256', 'dump'));
});

it('adopts a file of the work directory without transferring it, after checking its checksum', function (): void {
    $driver = new FakeDriver;
    $dump = $driver->dumpDatabase('db-run1-abcd.sql.gz');
    $target = fakeLocalTarget($driver);
    $driver->writeCalls = [];

    $stored = $target->adopt($dump, 'site-1/run-1/db.sql.gz');

    expect($stored->key)->toBe('site-1/run-1/db.sql.gz')
        ->and($stored->sizeBytes)->toBe($dump->sizeBytes)
        ->and($target->checksum('site-1/run-1/db.sql.gz'))->toBe($dump->sha256)
        ->and($driver->files)->not->toHaveKey('db-run1-abcd.sql.gz')
        ->and($driver->writeCalls)->toBe(['moveWorkFile']);
});

it('refuses to adopt a file that changed, is missing or would overwrite an object', function (): void {
    $driver = new FakeDriver;
    $dump = $driver->dumpDatabase('db-run1-abcd.sql.gz');
    $other = $driver->dumpDatabase('db-run2-abcd.sql.gz');
    $target = fakeLocalTarget($driver);
    $target->adopt($other, 'site-1/run-1/db.sql.gz');

    expect(fn () => $target->adopt(new RemoteFile($dump->path, $dump->sizeBytes, str_repeat('0', 64)), 'site-1/run-2/db.sql.gz'))
        ->toThrow(BackupTargetException::class, 'checksum')
        ->and(fn () => $target->adopt(new RemoteFile('db-none.sql.gz', 1, str_repeat('0', 64)), 'site-1/run-2/db.sql.gz'))
        ->toThrow(BackupTargetException::class, 'no existeix')
        ->and(fn () => $target->adopt($dump, 'site-1/run-1/db.sql.gz'))
        ->toThrow(BackupTargetException::class, 'ja existeix')
        ->and(fn () => $target->adopt($dump, '../escape.gz'))
        ->toThrow(BackupTargetException::class, 'no vàlida')
        ->and($driver->files)->toHaveKey('db-run1-abcd.sql.gz');
});

it('never writes when the work directory is inside the WordPress root', function (): void {
    $driver = new FakeDriver;
    $driver->workDirInRoot = '.wpup';
    $dump = $driver->dumpDatabase('db-run1-abcd.sql.gz');
    $target = fakeLocalTarget($driver);

    expect(fn () => $target->put('site-1/r1/db.sql.gz', BackupTargetContract::stream('x')))->toThrow(BackupTargetException::class, 'accessibles pel web')
        ->and(fn () => $target->adopt($dump, 'site-1/r1/db.sql.gz'))->toThrow(BackupTargetException::class, 'accessibles pel web')
        ->and(array_keys($driver->files))->toBe(['db-run1-abcd.sql.gz']);
});

it('leaves no temporary upload behind when publishing fails', function (): void {
    $driver = new class extends FakeDriver
    {
        public function moveWorkFile(string $from, string $to): WorkFile
        {
            throw new RemoteCommandFailed('disc ple');
        }
    };
    $target = fakeLocalTarget($driver);

    expect(fn () => $target->put('site-1/r1/db.sql.gz', BackupTargetContract::stream('x')))->toThrow(BackupTargetException::class, 'disc ple')
        ->and($driver->files)->toBe([]);
});

it('links every new site to the shared site-local destination, which the registry resolves', function (): void {
    $site = Site::factory()->create();
    $destination = app(SiteLocalDestination::class);
    $destination->attach($site);
    $destination->attach($site);
    $record = $destination->record();

    expect($record->type)->toBe(BackupTargetType::SiteLocal)
        ->and(BackupTargetRecord::query()->where('type', BackupTargetType::SiteLocal)->count())->toBe(1)
        ->and($site->backupTargets()->pluck('backup_targets.id')->all())->toBe([$record->id])
        ->and(app(BackupTargetRegistry::class)->has(BackupTargetType::SiteLocal))->toBeTrue()
        // Resolving it builds the site's driver: a driver failure is a destination failure.
        ->and(fn () => app(BackupTargetRegistry::class)->for($record, $site, AuditActor::system('tests')))->toThrow(BackupTargetException::class, 'clau SSH');
});

it('links a site created by site:add to the site-local destination', function (): void {
    User::factory()->create(['unix_username' => 'admin1', 'role' => UserRole::Admin]);
    actingAsOperator('admin1');

    command('site:add', [
        'name' => 'botiga-local',
        '--url' => 'https://botiga-local.test',
        '--wp-path' => '/var/www/botiga-local',
        '--server' => 'srv1',
        '--hostname' => 'srv1.example.test',
        '--account' => 'botiga_local',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $site = Site::query()->where('name', 'botiga-local')->sole();

    expect($site->backupTargets()->pluck('type')->all())->toBe([BackupTargetType::SiteLocal]);
});
