<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupTargetException;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalSettings;
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
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Contracts\BackupTargetContract;
use Tests\Support\FakeDriver;

/*
 * SiteLocalTarget over FakeDriver (UZEROWPUP-40). The contract suite runs
 * against the Docker test sites (tests/Integration/Backups/SiteLocalTargetTest.php):
 * FakeDriver keeps files in memory and cannot pass its bounded-memory test.
 */

function fakeLocalTarget(FakeDriver $driver, string $url = 'https://fake.test'): SiteLocalTarget
{
    return new SiteLocalTarget($driver, app(SecretRedactor::class), app(HttpFactory::class), app(SiteLocalSettings::class)->withRoot('restore-points'), $url);
}

/**
 * A FakeDriver whose WordPress root is a subdirectory of the docroot, with the
 * work directory in the docroot too (outside the WordPress root): the web
 * could serve the store.
 */
function servableFakeDriver(): FakeDriver
{
    $driver = new FakeDriver;
    $driver->archiveRoot = $driver->home.'/httpdocs/blog';
    $driver->workDirPath = $driver->home.'/httpdocs/wpup';

    return $driver;
}

beforeEach(function (): void {
    // Only the destinations whose store the web could serve make requests: each such test fakes them.
    Http::preventStrayRequests();
});

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
        // The checksum it verified before linking the file into place.
        ->and($stored->sha256)->toBe($dump->sha256)
        ->and($target->checksum('site-1/run-1/db.sql.gz'))->toBe($dump->sha256)
        ->and($driver->files)->not->toHaveKey('db-run1-abcd.sql.gz')
        ->and($driver->writeCalls)->toBe(['moveWorkFile']);
});

it('refuses to adopt a file that changed, is missing or would overwrite an object', function (): void {
    $driver = new FakeDriver;
    $dump = $driver->dumpDatabase('db-run1-abcd.sql.gz');
    $driver->options['blogname'] = 'Una altra botiga';
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

    expect(fn () => $target->assertWritable())->toThrow(BackupTargetException::class, 'accessibles pel web')
        ->and(fn () => $target->put('site-1/r1/db.sql.gz', BackupTargetContract::stream('x')))->toThrow(BackupTargetException::class, 'accessibles pel web')
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

it('refuses every write when the web serves the store, and removes its canary', function (): void {
    $driver = servableFakeDriver();
    $requested = [];
    // The site's web serves the work directory: answers with the file the URL names.
    Http::fake(function (Request $request) use ($driver, &$requested) {
        $requested[] = $request->url();
        $path = (string) preg_replace('#^https://fake\.test/wpup/#', '', $request->url());

        return isset($driver->files[$path]) ? Http::response($driver->files[$path]) : Http::response('', 404);
    });
    $target = fakeLocalTarget($driver, 'https://fake.test/blog');

    expect(fn () => $target->put('site-1/r1/db.sql.gz', BackupTargetContract::stream('x')))->toThrow(BackupTargetException::class, 'El web serveix')
        ->and($requested)->toHaveCount(1)
        ->and($requested[0])->toMatch('#^https://fake\.test/wpup/restore-points/wpup-canary-[0-9a-f]{32}\.txt$#')
        ->and($driver->files)->toBe([]);
});

it('writes when the web does not serve the store, or does not answer at all', function (): void {
    $driver = servableFakeDriver();
    // Two candidate URLs per target (docroot and home level): the first target
    // gets 404s, the second cannot connect.
    Http::fakeSequence()->push('Not Found', 404)->push('Not Found', 404)->pushFailedConnection()->pushFailedConnection();
    $target = fakeLocalTarget($driver, 'https://fake.test/blog');
    $target->put('site-1/r1/db.sql.gz', BackupTargetContract::stream('x'));

    $offline = fakeLocalTarget($driver, 'https://fake.test/blog');
    $offline->put('site-1/r2/db.sql.gz', BackupTargetContract::stream('y'));

    // Both candidate URLs (docroot and home level), for each target.
    Http::assertSentCount(4);
    expect(array_keys($driver->files))->toBe(['restore-points/site-1/r1/db.sql.gz', 'restore-points/site-1/r2/db.sql.gz']);
});

it('checks the cheap conditions before copying the stream', function (): void {
    $driver = new FakeDriver;
    $driver->workDirInRoot = '.wpup';
    $stream = BackupTargetContract::stream('payload');
    fseek($stream, 3);

    expect(fn () => fakeLocalTarget($driver)->put('site-1/r1/db.sql.gz', $stream))->toThrow(BackupTargetException::class, 'accessibles pel web')
        ->and(ftell($stream))->toBe(3)
        ->and(glob(config()->string('wpup.backups.staging_dir').'/*') ?: [])->toBe([]);
});

it('stages temporary copies in the private staging directory, not /tmp, and leaves nothing there', function (): void {
    $staging = config()->string('wpup.backups.staging_dir');
    fakeLocalTarget(new FakeDriver)->put('site-1/r1/db.sql.gz', BackupTargetContract::stream('x'));

    expect(str_starts_with($staging, sys_get_temp_dir()))->toBeFalse()
        ->and(fileperms($staging) & 0777)->toBe(0700)
        ->and(glob($staging.'/*') ?: [])->toBe([]);
});

it('treats a move that failed after linking as done when the object holds the expected bytes', function (): void {
    // ln succeeded, then a later step (chmod, the connection…) failed.
    $driver = new class extends FakeDriver
    {
        public function moveWorkFile(string $from, string $to): WorkFile
        {
            parent::moveWorkFile($from, $to);

            throw new RemoteCommandFailed('connexió perduda');
        }
    };
    $target = fakeLocalTarget($driver);

    $stored = $target->put('site-1/r1/db.sql.gz', BackupTargetContract::stream('dump'));

    expect($stored->sizeBytes)->toBe(4)
        ->and(array_keys($driver->files))->toBe(['restore-points/site-1/r1/db.sql.gz']);
});

it('is idempotent for the same bytes under the same key, and refuses others', function (): void {
    $driver = new FakeDriver;
    $target = fakeLocalTarget($driver);
    $target->put('site-1/r1/db.sql.gz', BackupTargetContract::stream('dump'));
    $dump = $driver->dumpDatabase('db-run1-abcd.sql.gz');
    $target->adopt($dump, 'site-1/r1/db2.sql.gz');
    $copy = $driver->dumpDatabase('db-run1-abcd.sql.gz');

    expect($target->put('site-1/r1/db.sql.gz', BackupTargetContract::stream('dump'))->sizeBytes)->toBe(4)
        ->and($target->adopt($copy, 'site-1/r1/db2.sql.gz')->sizeBytes)->toBe($dump->sizeBytes)
        ->and($driver->files)->not->toHaveKey('db-run1-abcd.sql.gz')
        ->and(fn () => $target->put('site-1/r1/db.sql.gz', BackupTargetContract::stream('other')))->toThrow(BackupTargetException::class, 'ja existeix')
        ->and(array_keys($driver->files))->toBe(['restore-points/site-1/r1/db.sql.gz', 'restore-points/site-1/r1/db2.sql.gz']);
});

it('logs the move failure when checking its result fails too', function (): void {
    $driver = new class extends FakeDriver
    {
        public bool $broken = false;

        public function moveWorkFile(string $from, string $to): WorkFile
        {
            $this->broken = true;

            throw new RemoteCommandFailed('ln: disc ple');
        }

        public function statWorkFile(string $path, bool $withChecksum = false): ?WorkFile
        {
            return $this->broken ? throw new RemoteCommandFailed('connexió perduda') : parent::statWorkFile($path, $withChecksum);
        }
    };
    Log::shouldReceive('warning')->once()->withArgs(static fn (string $message, array $context): bool => is_string($context['reason'] ?? null) && str_contains($context['reason'], 'disc ple'));
    Log::shouldReceive('warning');

    expect(fn () => fakeLocalTarget($driver)->put('site-1/r1/db.sql.gz', BackupTargetContract::stream('x')))
        ->toThrow(BackupTargetException::class, 'connexió perduda');
});

it('purges only its own stale temporary files, before each write', function (): void {
    $driver = new FakeDriver;
    $old = time() - 2 * 86_400;
    $hex = str_repeat('ab', 16);
    foreach ([
        "store-{$hex}.part" => $old,
        "store-{$hex}.part.part" => $old,
        "restore-points/wpup-canary-{$hex}.txt" => $old,
        'store-'.str_repeat('cd', 16).'.part' => time(),
        'store-short.part' => $old,
        'db-old.sql.gz' => $old,
        "restore-points/site-1/store-{$hex}.part" => $old,
    ] as $path => $mtime) {
        $driver->files[$path] = 'x';
        $driver->fileTimes[$path] = $mtime;
    }

    fakeLocalTarget($driver)->put('site-1/r1/db.sql.gz', BackupTargetContract::stream('x'));

    expect(array_keys($driver->files))->toBe([
        'store-'.str_repeat('cd', 16).'.part',
        'store-short.part',
        'db-old.sql.gz',
        "restore-points/site-1/store-{$hex}.part",
        'restore-points/site-1/r1/db.sql.gz',
    ])->and(fakeLocalTarget($driver)->purgeStale(0))->toBe(0);
});

it('names the shared destination from config, or an alternative if another type took the name', function (): void {
    BackupTargetRecord::query()->where('type', BackupTargetType::SiteLocal)->delete();
    BackupTargetRecord::factory()->create(['name' => 'local', 'type' => BackupTargetType::Sftp]);

    expect(app(SiteLocalDestination::class)->record()->name)->toBe('local-site-local')
        ->and(SiteLocalDestination::nameFor('local', static fn (string $n): bool => false))->toBe('local')
        ->and(fn () => SiteLocalDestination::nameFor('local', static fn (string $n): bool => true))
        ->toThrow(BackupTargetException::class, 'ja són d\'altres destinacions');
});
