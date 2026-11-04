<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Locks\LockedException;
use App\Domain\Locks\SiteLockManager;
use App\Domain\Runs\Concurrency\ConcurrencyGate;
use App\Domain\Runs\Exceptions\RunWaitTimedOut;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunEngine;
use App\Domain\Runs\RunFollower;
use App\Domain\Runs\RunLauncher;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Secrets\SecretStore;
use App\Enums\AuditChannel;
use App\Enums\AuditResult;
use App\Enums\RunStatus;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Enums\UserRole;
use App\Jobs\ExecuteRun;
use App\Models\AuditLogEntry;
use App\Models\HostingAccount;
use App\Models\Run;
use App\Models\RunStep;
use App\Models\Site;
use App\Models\User;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;
use Tests\Support\Runs\ScriptedStep;

/*
 * Credential operations run on the workers (ADR-0011, UZEROWPUP-138).
 */

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
});

it('runs a site test while an update holds the global slot and the site lock', function (): void {
    $connection = Redis::connection('locks');
    assert($connection instanceof PhpRedisConnection);
    $gate = new ConcurrencyGate($connection, ['global' => 1, 'per_hosting_server' => 1, 'staging' => 1], 60);
    app()->instance(ConcurrencyGate::class, $gate);
    app(PipelineResolver::class)->register(RunType::SiteTest, static fn (): Pipeline => new Pipeline([
        new ScriptedStep('site_test', static fn (): StepOutcome => StepOutcome::succeeded('ok')),
    ]));

    $site = Site::factory()->create();
    $update = Run::factory()->create(['site_id' => $site->id]);
    $slot = $gate->acquire($update, 'worker-1');
    $lock = app(SiteLockManager::class)->acquire($site, 'nightly update', $update->id);

    try {
        $test = Run::factory()->create(['site_id' => $site->id, 'type' => RunType::SiteTest]);

        expect(app(RunEngine::class)->execute($test)->status)->toBe(RunStatus::Succeeded);
    } finally {
        $lock->release();
        $slot->release();
    }
});

it('refuses on the worker a site test whose operator was deactivated after queueing', function (): void {
    $user = User::factory()->create(['unix_username' => 'oper9', 'active' => false]);
    $run = Run::factory()->create([
        'type' => RunType::SiteTest,
        'user_id' => $user->id,
        'actor' => 'oper9',
        'channel' => AuditChannel::Cli,
    ]);

    $final = app(RunEngine::class)->execute($run);

    expect($final->status)->toBe(RunStatus::Failed)
        ->and($final->steps()->value('summary'))->toContain('ja no té permís')
        ->and(AuditLogEntry::query()->where('action', 'run.denied')->where('object_id', (string) $run->id)->value('result'))->toBe(AuditResult::Failure)
        ->and(AuditLogEntry::query()->where('action', 'secret.reveal')->exists())->toBeFalse();
});

it('puts only the run id on the queue, never the credential', function (): void {
    Queue::fake();
    $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nqueue-must-never-see-this-test-only\n-----END OPENSSH PRIVATE KEY-----";
    $account = HostingAccount::factory()->create([
        'credential_ref' => app(SecretStore::class)->put('ssh-key', new Secret($key), AuditActor::system('tests')),
    ]);
    $site = Site::factory()->for($account)->create();
    $actor = new AuditActor(null, 'oper1', AuditChannel::Cli);

    [$run] = app(RunLauncher::class)->launch(RunType::SiteTest, [$site], $actor, ['dry_run' => false]);

    Queue::assertPushed(ExecuteRun::class, static function (ExecuteRun $job) use ($run): bool {
        $payload = serialize($job);

        return $job->runId === $run->id
            && ! str_contains($payload, 'queue-must-never-see-this')
            && ! str_contains($payload, 'OPENSSH');
    });
    expect(json_encode($run->fresh()?->flags, JSON_THROW_ON_ERROR))->toBe('{"dry_run":false}');
});

it('waits for the hosting account lock before rotating a key', function (): void {
    $account = HostingAccount::factory()->create();
    $site = Site::factory()->for($account)->create();
    app(PipelineResolver::class)->register(RunType::KeyRotation, static fn (): Pipeline => new Pipeline([
        new ScriptedStep('key_rotation', static fn (): StepOutcome => StepOutcome::succeeded('ok')),
    ]));
    $update = app(SiteLockManager::class)->acquire($site, 'nightly update', 41);
    $rotation = Run::factory()->create(['site_id' => null, 'hosting_account_id' => $account->id, 'type' => RunType::KeyRotation]);

    try {
        expect(fn () => app(RunEngine::class)->execute($rotation))->toThrow(LockedException::class)
            ->and($rotation->fresh()?->status)->toBe(RunStatus::Queued);
    } finally {
        $update->release();
    }

    expect(app(RunEngine::class)->execute($rotation)->status)->toBe(RunStatus::Succeeded);
});

it('refuses on the worker a key rotation by an admin who was demoted', function (): void {
    $user = User::factory()->create(['unix_username' => 'admin9', 'role' => UserRole::Operator]);
    $account = HostingAccount::factory()->create();
    $run = Run::factory()->create([
        'site_id' => null,
        'hosting_account_id' => $account->id,
        'type' => RunType::KeyRotation,
        'user_id' => $user->id,
        'actor' => 'admin9',
        'channel' => AuditChannel::Cli,
    ]);

    expect(app(RunEngine::class)->execute($run)->status)->toBe(RunStatus::Failed)
        ->and(AuditLogEntry::query()->where('action', 'run.denied')->where('object_id', (string) $run->id)->exists())->toBeTrue()
        ->and(AuditLogEntry::query()->where('action', 'like', 'ssh_key.%')->exists())->toBeFalse();
});

it('forgets the revealed secrets when the worker finishes a run', function (): void {
    app(PipelineResolver::class)->register(RunType::SiteTest, static fn (): Pipeline => new Pipeline([
        new ScriptedStep('site_test', static fn (): StepOutcome => StepOutcome::succeeded('ok')),
    ]));
    $redactor = app(SecretRedactor::class);
    $redactor->register('revealed-during-the-run');
    $run = Run::factory()->create(['site_id' => Site::factory()->create()->id, 'type' => RunType::SiteTest]);

    (new ExecuteRun($run->id))->handle(app(RunEngine::class), $redactor);

    expect($redactor->redact('revealed-during-the-run'))->toBe('revealed-during-the-run');
});

it('stops waiting for a run that no worker takes, and says so', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $queued = Run::factory()->create(['type' => RunType::SiteTest]);
    $noticed = 0;
    $timedOut = false;

    try {
        app(RunFollower::class)->wait([$queued->id], timeoutSeconds: 5, onStillQueued: function () use (&$noticed): void {
            $noticed++;
        }, queuedNoticeSeconds: 2);
    } catch (RunWaitTimedOut) {
        $timedOut = true;
    }

    expect($timedOut)->toBeTrue()->and($noticed)->toBe(1);
});

it('warns and gives up in site:test when no worker runs', function (): void {
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);
    config([
        'wpup.credential_operations.site_test_timeout_seconds' => 3,
        'wpup.credential_operations.wait_margin_seconds' => 0,
        'wpup.credential_operations.queued_notice_seconds' => 1,
    ]);
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('oper1');
    $site = Site::factory()->create();
    $site->hostingAccount()->firstOrFail()->hostingServer()->firstOrFail()->update(['pinned_host_key' => 'ssh-ed25519 AAAAtest']);

    command('site:test', ['sites' => [$site->name]])
        ->expectsOutputToContain('Encara en cua')
        ->expectsOutputToContain("Deixo d'esperar")
        ->assertFailed();
});

it('reports the progress a running step writes to its summary', function (): void {
    $run = Run::factory()->create(['type' => RunType::SecretsRekey, 'site_id' => null, 'status' => RunStatus::Running]);
    $step = RunStep::factory()->for($run)->create(['name' => 'secrets_rekey', 'status' => RunStepStatus::Running, 'summary' => '50/100']);
    $polls = 0;
    Sleep::fake();
    Sleep::whenFakingSleep(static function () use (&$polls, $run, $step): void {
        $polls++;
        $polls === 1
            ? $step->update(['summary' => '100/100'])
            : $run->update(['status' => RunStatus::Succeeded]);
    });
    $seen = [];

    app(RunFollower::class)->wait([$run->id], function (Run $run, RunStep $step) use (&$seen): void {
        $seen[] = $step->summary;
    });

    expect($seen)->toBe(['50/100', '100/100']);
});

it('labels a key rotation run with its hosting account, not as fleet-wide', function (): void {
    $account = HostingAccount::factory()->create(['system_user' => 'botiga']);
    $run = Run::factory()->create(['site_id' => null, 'hosting_account_id' => $account->id, 'type' => RunType::KeyRotation]);

    expect($run->fresh()?->targetLabel())->toBe("compte botiga@{$account->hostingServer()->firstOrFail()->name}")
        ->and(Run::factory()->create(['site_id' => null])->targetLabel())->toBe('flota');
});
