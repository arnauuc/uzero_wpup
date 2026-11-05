<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\RestorePointRetention;
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Identity\CliCommandGate;
use App\Domain\Locks\SiteLockManager;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\AuditChannel;
use App\Enums\AuditResult;
use App\Enums\BackupTargetType;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\Backup;
use App\Models\Run;
use App\Models\Site;
use App\Models\SiteProfile;
use App\Models\User;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeDriver;
use Tests\Support\RetentionFixture;
use Tests\Support\UndeletableTarget;

/*
 * `wpup backup:prune`, the scheduled `engine:prune-restore-points` and the
 * retention applied right after making a restore point (UZEROWPUP-44).
 */

const PRUNE_QUESTION = 'Esborrar aquests 1 punts de restauració? No es pot desfer.';

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    Http::preventStrayRequests();
    RetentionFixture::setUp();
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('oper1');
});

afterEach(function (): void {
    RetentionFixture::tearDown();
});

/**
 * A site with three correct points: the oldest is surplus.
 *
 * @return array{string, string, string}
 */
function pruneSiteWithThree(string $name = 'botiga-prune'): array
{
    $site = RetentionFixture::site(external: true, name: $name);

    return [
        RetentionFixture::point($site, RetentionFixture::run($site), 3),
        RetentionFixture::point($site, RetentionFixture::run($site), 2),
        RetentionFixture::point($site, RetentionFixture::run($site), 1),
    ];
}

function livePruneRows(): int
{
    return Backup::query()->whereNull('deleted_at')->count();
}

it('shows what it would purge with --dry-run and changes nothing', function (): void {
    [$old] = pruneSiteWithThree();
    $rows = livePruneRows();

    command('backup:prune', ['--dry-run' => true])
        ->expectsOutputToContain("[dry-run] Punts de restauració que s'esborrarien")
        ->expectsOutputToContain($old)
        ->assertSuccessful();

    expect(livePruneRows())->toBe($rows)
        ->and(RetentionFixture::stored($old))->toBeTrue()
        ->and(AuditLogEntry::query()->where('action', 'backups.pruned')->exists())->toBeFalse();
});

it('purges after the operator confirms, audited as the operator', function (): void {
    [$old, $middle, $new] = pruneSiteWithThree();

    command('backup:prune')
        ->expectsConfirmation(PRUNE_QUESTION, 'yes')
        ->expectsOutputToContain('Punts de restauració esborrats: 1.')
        ->assertSuccessful();

    $audit = AuditLogEntry::query()->where('action', 'backups.pruned')->sole();
    expect(RetentionFixture::stored($old))->toBeFalse()
        ->and(RetentionFixture::stored($old, external: true))->toBeFalse()
        ->and(RetentionFixture::stored($middle))->toBeTrue()
        ->and($audit->actor)->toBe('oper1')
        ->and($audit->channel)->toBe(AuditChannel::Cli)
        ->and($audit->context['trigger'] ?? null)->toBe('backup:prune');
});

it('changes nothing when the operator does not confirm', function (): void {
    [$old] = pruneSiteWithThree();
    $rows = livePruneRows();

    command('backup:prune')->expectsConfirmation(PRUNE_QUESTION, 'no')->assertFailed();

    expect(livePruneRows())->toBe($rows)->and(RetentionFixture::stored($old))->toBeTrue();
});

it('lists the points it keeps and why', function (): void {
    $site = RetentionFixture::site(name: 'botiga-revertida');
    $point = RetentionFixture::point($site, RetentionFixture::run($site, RunStatus::RolledBack), 9, protected: true);

    command('backup:prune', ['--dry-run' => true])
        ->expectsOutputToContain('No hi ha cap punt de restauració per esborrar.')
        ->expectsOutputToContain($point)
        ->assertSuccessful();
});

it('limits the purge to the selected sites, with the usual selection messages', function (): void {
    [$old] = pruneSiteWithThree('botiga-a');
    [$otherOld] = pruneSiteWithThree('botiga-b');

    command('backup:prune', ['sites' => ['botiga-b'], '--force' => true])->assertSuccessful();

    expect(RetentionFixture::stored($old))->toBeTrue()
        ->and(RetentionFixture::stored($otherOld))->toBeFalse();

    command('backup:prune', ['sites' => ['fantasma']])->expectsOutputToContain('Llocs desconeguts: fantasma.')->assertFailed();
    command('backup:prune', ['sites' => ['botiga-a'], '--all' => true])->expectsOutputToContain('només una forma')->assertFailed();
});

it('prints a versioned JSON document, and needs --force or --dry-run with --json', function (): void {
    [$old] = pruneSiteWithThree();

    command('backup:prune', ['--json' => true])->expectsOutputToContain('cal --dry-run o --force')->assertFailed();
    expect(RetentionFixture::stored($old))->toBeTrue();

    expect(Artisan::call('backup:prune', ['--json' => true, '--force' => true]))->toBe(0);
    /** @var array{version: int, dry_run: bool, max_per_site: int, purges: list<array{directory: string, reason: string, backups: list<array{destination: string}>}>, result: array{purged: list<array{directory: string}>, failures: list<mixed>}} $document */
    $document = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($document['version'])->toBe(1)
        ->and($document['dry_run'])->toBeFalse()
        ->and($document['max_per_site'])->toBe(2)
        ->and($document['purges'][0]['directory'])->toBe($old)
        ->and($document['purges'][0]['reason'])->toBe('over_cap')
        ->and($document['purges'][0]['backups'])->toHaveCount(4)
        ->and($document['result']['purged'][0]['directory'])->toBe($old)
        ->and($document['result']['failures'])->toBe([]);
});

it('exits with an error when a file could not be deleted, and the row stays', function (): void {
    [$old] = pruneSiteWithThree();
    app(BackupTargetRegistry::class)->register(BackupTargetType::Sftp, static fn (): BackupTarget => new UndeletableTarget);

    command('backup:prune', ['--force' => true])
        ->expectsOutputToContain('Es tornarà a provar a la propera neteja')
        ->assertFailed();

    expect(Backup::query()->where('path', 'like', "{$old}/%")->whereNull('deleted_at')->count())->toBe(2)
        ->and(AuditLogEntry::query()->where('action', 'backups.pruned')->sole()->result)->toBe(AuditResult::Failure);
});

it('runs from the scheduler without asking, audited as the system', function (): void {
    [$old] = pruneSiteWithThree();

    command('engine:prune-restore-points', ['--dry-run' => true])->assertSuccessful();
    expect(RetentionFixture::stored($old))->toBeTrue();

    command('engine:prune-restore-points')->assertSuccessful();

    $audit = AuditLogEntry::query()->where('action', 'backups.pruned')->sole();
    expect(RetentionFixture::stored($old))->toBeFalse()
        ->and($audit->channel)->toBe(AuditChannel::System)
        ->and($audit->user_id)->toBeNull()
        ->and($audit->context['trigger'] ?? null)->toBe('schedule');
});

it('schedules the purge daily, without overlapping, and keeps it out of the wpup binary', function (): void {
    $events = array_values(array_filter(app(Schedule::class)->events(), static fn (ScheduledEvent $e): bool => str_contains((string) $e->command, 'engine:prune-restore-points')));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('30 6 * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue();

    $gate = app(CliCommandGate::class);
    expect($gate->allows('engine:prune-restore-points'))->toBeFalse()
        ->and($gate->allows('backup:prune'))->toBeTrue()
        ->and($gate->allows('run:ack'))->toBeTrue();
});

/**
 * The restore point service with the site-local store served by $driver.
 */
function prunePointService(FakeDriver $driver): RestorePointService
{
    app(BackupTargetRegistry::class)->register(BackupTargetType::SiteLocal, static fn (): BackupTarget => new SiteLocalTarget(
        $driver, app(SecretRedactor::class), app(HttpFactory::class), app(SiteLocalSettings::class), 'https://fake.test',
    ));

    return app(RestorePointService::class);
}

/**
 * The local files of $directories, as the site-local store of $driver holds them.
 *
 * @param  list<string>  $directories
 */
function seedDriverStore(FakeDriver $driver, array $directories): void
{
    foreach ($directories as $directory) {
        foreach (glob(RetentionFixture::dir(false)."/{$directory}/*") ?: [] as $file) {
            $driver->files['restore-points/'.$directory.'/'.basename($file)] = (string) file_get_contents($file);
        }
    }
}

it('purges the oldest point right after making a third one, never the new one', function (): void {
    $site = RetentionFixture::site();
    SiteProfile::factory()->create(['site_id' => $site->id]);
    $old = RetentionFixture::point($site, RetentionFixture::run($site), 3);
    $middle = RetentionFixture::point($site, RetentionFixture::run($site), 2);
    $driver = new FakeDriver;
    seedDriverStore($driver, [$old, $middle]);
    $run = Run::factory()->create(['site_id' => $site->id, 'type' => RunType::Backup, 'status' => RunStatus::Running]);

    $point = prunePointService($driver)->create($site, $driver, $run, AuditActor::system('tests'));

    expect(RetentionFixture::live($site))->toBe([$middle, $point->directory])
        ->and(array_keys($driver->files))->not->toContain("restore-points/{$old}/database.gz")
        ->and(array_keys($driver->files))->toContain("restore-points/{$middle}/database.gz")
        ->and(AuditLogEntry::query()->where('action', 'backups.pruned')->sole()->context['trigger'] ?? null)->toBe('restore_point_created');
});

it('keeps a protected point when making a new one and purges the oldest unprotected one', function (): void {
    $site = RetentionFixture::site();
    SiteProfile::factory()->create(['site_id' => $site->id]);
    $protected = RetentionFixture::point($site, RetentionFixture::run($site, RunStatus::RolledBack), 5, protected: true);
    $middle = RetentionFixture::point($site, RetentionFixture::run($site), 2);
    $driver = new FakeDriver;
    seedDriverStore($driver, [$protected, $middle]);
    $run = Run::factory()->create(['site_id' => $site->id, 'type' => RunType::Backup, 'status' => RunStatus::Running]);

    $point = prunePointService($driver)->create($site, $driver, $run, AuditActor::system('tests'));

    expect(RetentionFixture::live($site))->toBe([$protected, $point->directory]);
});

it('still returns the new point when the purge after it fails', function (): void {
    $site = RetentionFixture::site(external: true);
    SiteProfile::factory()->create(['site_id' => $site->id]);
    $old = RetentionFixture::point($site, RetentionFixture::run($site), 3);
    $middle = RetentionFixture::point($site, RetentionFixture::run($site), 2);
    $driver = new FakeDriver;
    seedDriverStore($driver, [$old, $middle]);
    $run = Run::factory()->create(['site_id' => $site->id, 'type' => RunType::Backup, 'status' => RunStatus::Running]);
    app(BackupTargetRegistry::class)->register(BackupTargetType::Sftp, static fn (): BackupTarget => new UndeletableTarget);

    $point = prunePointService($driver)->create($site, $driver, $run, AuditActor::system('tests'));

    expect($point->directory)->toBe("site-{$site->id}/run{$run->id}")
        ->and(Backup::query()->where('path', 'like', "{$old}/%")->whereNull('deleted_at')->count())->toBe(2)
        ->and(AuditLogEntry::query()->where('action', 'backups.pruned')->sole()->result)->toBe(AuditResult::Failure);
});

it('never throws from the purge after making a point, and logs why', function (): void {
    $site = RetentionFixture::site();
    SiteProfile::factory()->create(['site_id' => $site->id]);
    $driver = new FakeDriver;
    $run = Run::factory()->create(['site_id' => $site->id, 'type' => RunType::Backup, 'status' => RunStatus::Running]);
    config(['wpup.backups.max_per_site' => 0]);
    $errors = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $e) use (&$errors): void {
        if ($e->level === 'error') {
            $errors[] = $e->message;
        }
    });

    $point = prunePointService($driver)->create($site, $driver, $run, AuditActor::system('tests'));

    expect($point->directory)->toBe("site-{$site->id}/run{$run->id}");
    expect($errors)->toBe(['Could not apply the retention after making a restore point.']);
});

it('leaves a busy site alone after a point made without a run', function (): void {
    $site = RetentionFixture::site();
    $old = RetentionFixture::point($site, RetentionFixture::run($site), 9);
    $lease = app(SiteLockManager::class)->acquire($site, 'una altra execució');

    try {
        $result = app(RestorePointRetention::class)->enforceAfterCreate($site, "site-{$site->id}/manual-nou", AuditActor::system('tests'), callerHoldsLock: false);
    } finally {
        $lease->release();
    }

    expect(array_map(static fn (Site $s): int => $s->id, $result->busySites))->toBe([$site->id])
        ->and(RetentionFixture::stored($old))->toBeTrue();
});
