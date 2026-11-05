<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetException;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\Dropbox\DropboxTargetFactory;
use App\Domain\Backups\FlysystemTarget;
use App\Domain\Backups\StoredObject;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Secrets\SecretStore;
use App\Domain\ToolBackup\ToolBackupCheckStep;
use App\Domain\ToolBackup\ToolBackupKey;
use App\Domain\ToolBackup\ToolBackupLauncher;
use App\Domain\ToolBackup\ToolBackupRetention;
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

it('deletes the upload and fails when the SHA-256 at the destination differs', function (): void {
    $f = toolBackupRunFixture();
    $inner = app(DropboxTargetFactory::class)->make($f->record, AuditActor::system('tests'));
    app(BackupTargetRegistry::class)->registerForTool(BackupTargetType::Dropbox, static fn (): BackupTarget => new readonly class($inner) implements BackupTarget
    {
        public function __construct(private FlysystemTarget $inner) {}

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
            return str_repeat('0', 64);
        }

        public function delete(string $key): void
        {
            $this->inner->delete($key);
        }

        public function list(string $prefix = ''): array
        {
            return $this->inner->list($prefix);
        }
    });

    $run = toolBackupExecute();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failureReason())->toContain('SHA-256')
        ->and($f->storedKeys())->toBe([])
        ->and(AuditLogEntry::query()->where('action', 'tool_backup.failed')->exists())->toBeTrue();
    $f->assertNoSecretsStored();
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
