<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupTargetException;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\Dropbox\DropboxTargetFactory;
use App\Domain\Backups\FlysystemTarget;
use App\Domain\Backups\RenamableBackupTarget;
use App\Domain\Backups\StoredObject;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Secrets\SecretStore;
use App\Domain\ToolBackup\ToolBackupCheckStep;
use App\Domain\ToolBackup\ToolBackupException;
use App\Domain\ToolBackup\ToolBackupKey;
use App\Domain\ToolBackup\ToolBackupLauncher;
use App\Domain\ToolBackup\ToolBackupRetention;
use App\Domain\ToolBackup\ToolBackupService;
use App\Domain\ToolBackup\ToolBackupStep;
use App\Enums\BackupTargetType;
use App\Enums\RunStatus;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Enums\UserRole;
use App\Jobs\ExecuteRun;
use App\Models\AuditLogEntry;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Run;
use App\Models\RunStep;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\Support\FakeDropboxApi;

/*
 * The tool backup run (UZEROWPUP-47) on the workers: check, dump, age,
 * upload through the real Dropbox target against the fake API, SHA-256 at the
 * destination and retention, audited and without secrets.
 */

final class ToolBackupRunFixture
{
    public FakeDropboxApi $fake;

    public BackupTargetRecord $record;

    public string $identity;

    public function __construct(public string $dir)
    {
        mkdir($dir.'/keys', 0700, true);
        file_put_contents($dir.'/config.env', "WPUP_APP_DIR=/opt/uzero-wpup\n");
        $this->identity = $dir.'/keys/identity.txt';
        (new Process(['age-keygen', '-o', $this->identity]))->mustRun();
        $recipient = trim((new Process(['age-keygen', '-y', $this->identity]))->mustRun()->getOutput());
        config([
            'wpup.tool_backup.age_recipients' => [$recipient],
            'wpup.tool_backup.config_paths' => [$dir.'/config.env'],
            'wpup.tool_backup.report_paths' => [$dir.'/reports'],
            'wpup.backups.staging_dir' => $dir.'/staging',
        ]);

        $this->fake = FakeDropboxApi::inTemp();
        app()->instance(DropboxTargetFactory::class, new DropboxTargetFactory(app(SecretStore::class), app(SecretRedactor::class), FakeDropboxApi::settings(), 1_048_576, $this->fake->__invoke(...)));
        $json = (string) json_encode(['app_key' => $this->fake->appKey, 'app_secret' => $this->fake->appSecret, 'refresh_token' => $this->fake->refreshToken]);
        $ref = app(SecretStore::class)->put('backup_target.dropbox', new Secret($json), AuditActor::system('tests'));
        $this->record = BackupTargetRecord::factory()->create(['name' => 'tool', 'type' => BackupTargetType::Dropbox, 'config' => ['root' => 'wpup'], 'credential_ref' => $ref]);
    }

    /** Where the fake API keeps tool/ of the destination. */
    public function stored(string $key = ''): string
    {
        return $this->fake->dir.'/files/wpup/'.$key;
    }

    public function seed(string $key): void
    {
        File::ensureDirectoryExists(dirname($this->stored($key)));
        file_put_contents($this->stored($key), 'old copy');
    }

    /**
     * @return list<string>
     */
    public function storedKeys(): array
    {
        if (! is_dir($this->stored('tool'))) {
            return [];
        }
        $keys = array_map(fn (SplFileInfo $f): string => substr($f->getPathname(), strlen($this->stored())), File::allFiles($this->stored('tool')));
        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    public function staged(): array
    {
        return is_dir($this->dir.'/staging') ? array_values(array_diff(scandir($this->dir.'/staging') ?: [], ['.', '..'])) : [];
    }

    public function assertNoSecretsStored(): void
    {
        $stored = json_encode([
            AuditLogEntry::query()->get(['context'])->toArray(),
            RunStep::query()->get(['summary', 'output', 'error', 'result'])->toArray(),
        ], JSON_THROW_ON_ERROR);

        expect(str_contains($stored, $this->fake->appSecret))->toBeFalse()
            ->and(str_contains($stored, $this->fake->refreshToken))->toBeFalse()
            ->and(str_contains($stored, 'AGE-SECRET-KEY'))->toBeFalse();
    }
}

function toolBackupRunFixture(): ToolBackupRunFixture
{
    return new ToolBackupRunFixture(storage_path('framework/testing/tool-run-'.bin2hex(random_bytes(6))));
}

function toolBackupExecute(bool $dryRun = false): Run
{
    // QUEUE_CONNECTION=sync: the worker job runs right away.
    $run = app(ToolBackupLauncher::class)->launch(AuditActor::system('tests'), $dryRun);

    return $run->refresh();
}

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    CarbonImmutable::setTestNow('2026-11-05 07:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    foreach (glob(storage_path('framework/testing/tool-run-*')) ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

it('uploads the encrypted copy, checks its SHA-256 at the destination and audits it', function (): void {
    $f = toolBackupRunFixture();
    $run = toolBackupExecute();

    $key = 'tool/wpup-20261105-070000.tar.age';
    $step = $run->steps()->where('name', ToolBackupStep::NAME)->firstOrFail();
    $audit = AuditLogEntry::query()->where('action', 'tool_backup.completed')->sole();

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->site_id)->toBeNull()
        ->and($f->storedKeys())->toBe([$key])
        ->and($step->result['sha256'] ?? null)->toBe(hash_file('sha256', $f->stored($key)))
        ->and($audit->context['key'] ?? null)->toBe($key)
        ->and($audit->context['files'] ?? null)->toBe([$f->dir.'/config.env'])
        ->and($f->staged())->toBe([]);

    // Arnau's key opens it.
    (new Process(['age', '--decrypt', '--identity', $f->identity, '--output', $f->dir.'/out.tar', $f->stored($key)]))->mustRun();
    expect((new Process(['tar', '--list', '--file='.$f->dir.'/out.tar']))->mustRun()->getOutput())->toContain('db.dump', 'manifest.json', 'files'.$f->dir.'/config.env');
    $f->assertNoSecretsStored();
});

it('applies the retention after the upload, auditing each deletion', function (): void {
    $f = toolBackupRunFixture();
    $old = array_map(static fn (int $i): string => ToolBackupKey::for(CarbonImmutable::parse('2026-09-26 07:00:00')->addDays($i)), range(0, 39));
    array_map($f->seed(...), [...$old, 'tool/notes.txt']);

    $run = toolBackupExecute();
    $new = 'tool/wpup-20261105-070000.tar.age';
    $expected = (new ToolBackupRetention(30, 12))->plan([...$old, $new]);
    $deleted = AuditLogEntry::query()->where('action', 'tool_backup.deleted')->orderBy('id')->get()->map(static fn (AuditLogEntry $e): mixed => $e->context['key'] ?? null)->all();

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($expected->delete)->toHaveCount(9)
        ->and($deleted)->toBe($expected->delete)
        ->and($f->storedKeys())->toBe(['tool/notes.txt', ...$expected->keep])
        ->and($expected->keep)->toContain($new, 'tool/wpup-20260926-070000.tar.age', 'tool/wpup-20261001-070000.tar.age');
});

it('only checks and reports under --dry-run', function (): void {
    $f = toolBackupRunFixture();
    array_map($f->seed(...), array_map(static fn (int $i): string => ToolBackupKey::for(CarbonImmutable::parse('2026-09-01 07:00:00')->addDays($i)), range(0, 39)));
    $before = $f->storedKeys();

    $run = toolBackupExecute(dryRun: true);
    $check = $run->steps()->where('name', ToolBackupCheckStep::NAME)->firstOrFail();
    $backup = $run->steps()->where('name', ToolBackupStep::NAME)->firstOrFail();

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($check->result['would_delete'] ?? null)->toHaveCount(10)
        ->and($check->result['files'] ?? null)->toBe([$f->dir.'/config.env'])
        ->and($backup->status)->toBe(RunStepStatus::Skipped)
        ->and($backup->summary)->toContain('[dry-run]', 'tool/wpup-20261105-070000.tar.age')
        ->and($f->storedKeys())->toBe($before)
        ->and($f->staged())->toBe([])
        ->and(AuditLogEntry::query()->whereIn('action', ['tool_backup.completed', 'tool_backup.deleted'])->exists())->toBeFalse();
});

it('refuses to run without age recipients, before dumping anything', function (): void {
    $f = toolBackupRunFixture();
    config(['wpup.tool_backup.age_recipients' => []]);

    $run = toolBackupExecute();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failureReason())->toContain('destinatari')
        ->and($run->steps()->where('name', ToolBackupStep::NAME)->firstOrFail()->status)->toBe(RunStepStatus::Pending)
        ->and($f->storedKeys())->toBe([])
        ->and($f->staged())->toBe([]);
});

/**
 * The real Dropbox target with scripted faults, registered for the tool.
 */
final class FaultyToolTarget implements RenamableBackupTarget
{
    public bool $lieChecksum = false;

    public bool $throwChecksum = false;

    public bool $failDelete = false;

    public function __construct(private readonly FlysystemTarget $inner) {}

    public static function install(ToolBackupRunFixture $f): self
    {
        $target = new self(app(DropboxTargetFactory::class)->make($f->record, AuditActor::system('tests')));
        app(BackupTargetRegistry::class)->registerForTool(BackupTargetType::Dropbox, static fn (): RenamableBackupTarget => $target);

        return $target;
    }

    public function put(string $key, $stream): StoredObject
    {
        return $this->inner->put($key, $stream);
    }

    public function readStream(string $key)
    {
        return $this->inner->readStream($key);
    }

    public function exists(string $key): bool
    {
        return $this->inner->exists($key);
    }

    public function checksum(string $key): string
    {
        if ($this->throwChecksum) {
            throw BackupTargetException::failed('El càlcul del checksum', $key, 'connexió tallada');
        }

        return $this->lieChecksum ? str_repeat('0', 64) : $this->inner->checksum($key);
    }

    public function delete(string $key): void
    {
        if ($this->failDelete) {
            throw BackupTargetException::failed("L'esborrat", $key, 'connexió tallada');
        }
        $this->inner->delete($key);
    }

    public function list(string $prefix = ''): array
    {
        return $this->inner->list($prefix);
    }

    public function rename(string $from, string $to): void
    {
        $this->inner->rename($from, $to);
    }
}

it('never leaves a final key when the SHA-256 at the destination differs or cannot be computed', function (string $fault): void {
    $f = toolBackupRunFixture();
    $target = FaultyToolTarget::install($f);
    $target->{$fault} = true;

    $run = toolBackupExecute();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failureReason())->toContain($fault === 'lieChecksum' ? 'SHA-256' : 'checksum')
        ->and($f->storedKeys())->toBe([])
        ->and(AuditLogEntry::query()->where('action', 'tool_backup.failed')->sole()->context['phase'] ?? null)->toBe('backup');
    $f->assertNoSecretsStored();
})->with(['lieChecksum', 'throwChecksum']);

it('audits a failed upload it cannot remove, keeps it out of the retention and removes it next time', function (): void {
    $f = toolBackupRunFixture();
    $target = FaultyToolTarget::install($f);
    $target->lieChecksum = true;
    $target->failDelete = true;

    $failed = toolBackupExecute();
    $left = $f->storedKeys();
    $cleanup = AuditLogEntry::query()->where('action', 'tool_backup.cleanup_failed')->sole();

    expect($failed->status)->toBe(RunStatus::Failed)
        ->and($left)->toHaveCount(1)
        ->and($left[0])->toStartWith('tool/pending/wpup-20261105-070000-')
        ->and(ToolBackupKey::timeOf($left[0]))->toBeNull()
        ->and($cleanup->context['key'] ?? null)->toBe($left[0]);

    $target->lieChecksum = false;
    $target->failDelete = false;
    CarbonImmutable::setTestNow('2026-11-05 07:05:00');
    $next = toolBackupExecute();

    expect($next->status)->toBe(RunStatus::Succeeded)
        ->and($f->storedKeys())->toBe(['tool/wpup-20261105-070500.tar.age'])
        ->and(AuditLogEntry::query()->where('action', 'tool_backup.deleted')->sole()->context['key'] ?? null)->toBe($left[0]);
});

it('audits every failure of the check, and unexpected errors too', function (): void {
    $f = toolBackupRunFixture();
    Site::factory()->create()->backupTargets()->attach($f->record->id);

    $run = toolBackupExecute(dryRun: true);
    $audit = AuditLogEntry::query()->where('action', 'tool_backup.failed')->sole();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failureReason())->toContain('la fan servir llocs')
        ->and($audit->context['phase'] ?? null)->toBe('check');

    $f->record->sites()->detach();
    app(BackupTargetRegistry::class)->registerForTool(BackupTargetType::Dropbox, static fn (): RenamableBackupTarget => throw new RuntimeException('boom'));

    expect(fn () => app(ToolBackupService::class)->run(AuditActor::system('tests'), CarbonImmutable::now()))->toThrow(RuntimeException::class, 'boom')
        ->and(AuditLogEntry::query()->where('action', 'tool_backup.failed')->orderByDesc('id')->firstOrFail()->context['reason'] ?? null)->toContain('Error inesperat', 'boom');
});

it('opens for the tool only an active destination that no site uses', function (array $change, string $message): void {
    /** @var array<string, mixed> $change */
    $f = toolBackupRunFixture();
    if ($change === ['site' => true]) {
        Site::factory()->create()->backupTargets()->attach($f->record->id);
    } else {
        $f->record->update($change);
    }

    expect(fn () => app(BackupTargetRegistry::class)->forTool($f->record->refresh(), AuditActor::system('tests')))
        ->toThrow(BackupTargetException::class, $message);
})->with([
    'linked to a site' => [['site' => true], 'la fan servir llocs'],
    'inactive' => [['active' => false], 'desactivada'],
    'site-local type' => [['type' => BackupTargetType::SiteLocal], 'no poden guardar la còpia de l\'eina'],
]);

it('refuses a tool destination that shares its folder with another Dropbox destination', function (string $root, bool $refused): void {
    $f = toolBackupRunFixture();
    BackupTargetRecord::factory()->create(['name' => 'altres', 'type' => BackupTargetType::Dropbox, 'config' => ['root' => $root]]);

    $open = fn () => app(BackupTargetRegistry::class)->forTool($f->record, AuditActor::system('tests'));

    $refused
        ? expect($open)->toThrow(BackupTargetException::class, 'comparteix carpeta amb «altres»')
        : expect($open())->toBeInstanceOf(RenamableBackupTarget::class);
})->with([
    'same root' => ['wpup', true],
    'inside it' => ['wpup/sites', true],
    'containing it' => ['', true],
    'a sibling with a common prefix' => ['wpup-sites', false],
    'elsewhere' => ['sites', false],
]);

it('fails clearly when the tool destination is missing', function (): void {
    toolBackupRunFixture()->record->update(['name' => 'other']);

    $run = toolBackupExecute();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failureReason())->toContain('«tool»');
});

it('queues it from the CLI and prints what the dry run found', function (): void {
    toolBackupRunFixture();
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('oper1');

    command('tool:backup', ['--dry-run' => true])
        ->expectsOutputToContain('tool_backup_check: Còpia «tool/wpup-20261105-070000.tar.age» a «tool»')
        ->expectsOutputToContain('[dry-run]')
        ->assertExitCode(0);

    expect(Run::query()->where('type', RunType::ToolBackup)->sole()->actor)->toBe('oper1');
});

it('asks before applying the retention, unless --force', function (): void {
    $f = toolBackupRunFixture();
    $f->seed('tool/wpup-20260101-070000.tar.age');
    $f->seed('tool/wpup-20260102-070000.tar.age');
    config(['wpup.tool_backup.retention.daily' => 1, 'wpup.tool_backup.retention.monthly' => 0]);
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('oper1');
    $question = "Fer ara la còpia de l'eina i esborrar-ne 2 de les antigues per la retenció?";

    command('tool:backup')
        ->expectsOutputToContain('esborraria tool/wpup-20260101-070000.tar.age')
        ->expectsConfirmation($question, 'no')
        ->assertExitCode(1);
    expect($f->storedKeys())->toBe(['tool/wpup-20260101-070000.tar.age', 'tool/wpup-20260102-070000.tar.age'])
        ->and(Run::query()->where('type', RunType::ToolBackup)->sole()->flags)->toBe(['dry_run' => true]);

    command('tool:backup')->expectsConfirmation($question, 'yes')->assertExitCode(0);
    expect($f->storedKeys())->toBe(['tool/wpup-20261105-070000.tar.age']);

    CarbonImmutable::setTestNow('2026-11-06 07:00:00');
    command('tool:backup', ['--force' => true])->assertExitCode(0);
    expect($f->storedKeys())->toBe(['tool/wpup-20261106-070000.tar.age']);
});

it('runs one at a time, whatever the state of the unfinished one', function (RunStatus $status): void {
    Queue::fake();
    $busy = Run::factory()->create(['type' => RunType::ToolBackup, 'site_id' => null, 'status' => $status]);

    expect(fn () => app(ToolBackupLauncher::class)->launch(AuditActor::system('tests')))->toThrow(ToolBackupException::class, "Ja n'hi ha una en curs: l'execució #{$busy->id}")
        ->and(app(ToolBackupLauncher::class)->scheduled())->toBeNull();
    Queue::assertNothingPushed();
})->with([RunStatus::Queued, RunStatus::Running, RunStatus::RollingBack, RunStatus::Interrupted]);

it('schedules it daily, and a deliberate skip is not a failed scheduled task', function (): void {
    Queue::fake();
    Run::factory()->create(['type' => RunType::ToolBackup, 'site_id' => null, 'status' => RunStatus::Running]);
    $events = array_values(array_filter(app(Schedule::class)->events(), static fn (ScheduledEvent $e): bool => $e->description === 'tool-backup'));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('0 7 * * *');

    $events[0]->run(app());

    expect($events[0]->exitCode)->toBe(0);
    Queue::assertNothingPushed();
});

it('queues the daily run once, without waiting for it', function (): void {
    Queue::fake();

    $first = app(ToolBackupLauncher::class)->scheduled();
    $second = app(ToolBackupLauncher::class)->scheduled();

    expect($first?->type)->toBe(RunType::ToolBackup)
        ->and($first?->user_id)->toBeNull()
        ->and($first?->flags)->toBe(['dry_run' => false])
        ->and($second)->toBeNull();
    Queue::assertPushed(ExecuteRun::class, 1);
});
