<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Locks\SiteLockManager;
use App\Domain\Runs\Concurrency\ConcurrencyGate;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunEngine;
use App\Domain\Runs\RunLauncher;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStore;
use App\Enums\AuditChannel;
use App\Enums\AuditResult;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Jobs\ExecuteRun;
use App\Models\AuditLogEntry;
use App\Models\HostingAccount;
use App\Models\Run;
use App\Models\Site;
use App\Models\User;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
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
