<?php

declare(strict_types=1);

use App\Domain\Identity\CliCommandGate;
use App\Domain\Locks\LockedException;
use App\Domain\Locks\LockLevel;
use App\Domain\Locks\SiteLockManager;
use App\Domain\Runs\Exceptions\ClaimLost;
use App\Domain\Runs\Exceptions\InvalidTransition;
use App\Domain\Runs\Exceptions\RunAlreadyClaimed;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunClaim;
use App\Domain\Runs\RunEngine;
use App\Domain\Runs\RunRecovery;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Runs\StepTicker;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\RunStatus;
use App\Enums\RunStepKind;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Jobs\ExecuteRun;
use App\Models\AuditLogEntry;
use App\Models\Run;
use App\Models\RunStep;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Runs\ScriptedCompensableStep;
use Tests\Support\Runs\ScriptedReadOnlyStep;
use Tests\Support\Runs\ScriptedStep;

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    config(['wpup.runs.heartbeat_seconds' => 1]);
});

/**
 * @param  list<Step>  $steps
 */
function withPipeline(array $steps): void
{
    app(PipelineResolver::class)->register(RunType::Update, static fn (): Pipeline => new Pipeline($steps));
}

function ok(string $summary = 'fet'): Closure
{
    return static fn (): StepOutcome => StepOutcome::succeeded($summary);
}

/**
 * @param  list<string>  $journal
 */
function journaling(array &$journal, string $entry, ?StepOutcome $outcome = null): Closure
{
    return static function () use (&$journal, $entry, $outcome): StepOutcome {
        $journal[] = $entry;

        return $outcome ?? StepOutcome::succeeded($entry);
    };
}

function engine(): RunEngine
{
    return app(RunEngine::class);
}

/**
 * @return list<array{string, string, string}> kind, name, status in execution order
 */
function stepRows(Run $run): array
{
    return array_values($run->steps()->orderBy('sequence')->get()
        ->map(static fn (RunStep $s): array => [$s->kind->value, $s->name, $s->status->value])
        ->all());
}

it('validates the run and step state machines', function (): void {
    expect(RunStatus::Queued->canTransitionTo(RunStatus::Running))->toBeTrue()
        ->and(RunStatus::Queued->canTransitionTo(RunStatus::Succeeded))->toBeFalse()
        ->and(RunStatus::Interrupted->canTransitionTo(RunStatus::RollingBack))->toBeTrue()
        ->and(RunStepStatus::Running->canTransitionTo(RunStepStatus::Pending))->toBeTrue()
        ->and(RunStepStatus::Succeeded->canTransitionTo(RunStepStatus::Running))->toBeFalse();

    foreach ([RunStatus::Succeeded, RunStatus::Failed, RunStatus::RolledBack, RunStatus::Cancelled, RunStatus::Skipped] as $terminal) {
        foreach (RunStatus::cases() as $to) {
            expect($terminal->canTransitionTo($to))->toBeFalse();
        }
    }

    $run = Run::factory()->create(['status' => RunStatus::Succeeded]);
    expect(fn () => RunEngine::transition($run, RunStatus::Running))->toThrow(InvalidTransition::class);
});

it('runs every step in order, records it and hands results forward', function (): void {
    $seen = null;
    withPipeline([
        new ScriptedStep('inventory', static fn (): StepOutcome => StepOutcome::succeeded('3 components', "core 6.8\n", ['core' => '6.8'])),
        new ScriptedStep('plan', static function (StepContext $c) use (&$seen): StepOutcome {
            $seen = $c->resultOf('inventory');

            return StepOutcome::succeeded('pla fet');
        }, timeout: 45),
    ]);
    $run = Run::factory()->create();

    engine()->execute($run);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->deadline_at)->not->toBeNull()
        ->and($seen)->toBe(['core' => '6.8'])
        ->and(stepRows($run))->toBe([['forward', 'inventory', 'succeeded'], ['forward', 'plan', 'succeeded']]);

    $first = $run->steps()->where('name', 'inventory')->firstOrFail();
    expect($first->attempts)->toBe(1)
        ->and($first->duration_ms)->toBeInt()
        ->and($first->summary)->toBe('3 components')
        ->and($first->output)->toBe("core 6.8\n")
        ->and($first->timeout_seconds)->toBe(30)
        ->and($run->steps()->where('name', 'plan')->value('timeout_seconds'))->toBe(45)
        ->and(AuditLogEntry::query()->where('object_type', 'run')->where('object_id', (string) $run->id)->pluck('action')->all())
        ->toBe(['run.started', 'run.finished'])
        ->and(app(RunClaim::class)->isClaimed($run->id))->toBeFalse()
        ->and(app(SiteLockManager::class)->holder(LockLevel::Site, (int) $run->site_id))->toBeNull();
});

it('does nothing when executing a finished run again', function (): void {
    $journal = [];
    withPipeline([new ScriptedStep('a', journaling($journal, 'a'))]);
    $run = Run::factory()->create();

    engine()->execute($run);
    engine()->execute($run);

    expect($journal)->toBe(['a'])->and($run->status)->toBe(RunStatus::Succeeded);
});

it('rolls back succeeded and failed compensable steps in reverse order', function (): void {
    $journal = [];
    withPipeline([
        new ScriptedCompensableStep('backup', journaling($journal, 'backup'), journaling($journal, 'undo backup')),
        new ScriptedStep('check', journaling($journal, 'check')),
        new ScriptedCompensableStep('update', journaling($journal, 'update', StepOutcome::failed('el plugin ha fallat')), journaling($journal, 'undo update')),
        new ScriptedStep('verify', journaling($journal, 'verify')),
    ]);
    $run = Run::factory()->create();

    engine()->execute($run);

    expect($run->status)->toBe(RunStatus::RolledBack)
        ->and($journal)->toBe(['backup', 'check', 'update', 'undo update', 'undo backup'])
        ->and(stepRows($run))->toBe([
            ['forward', 'backup', 'succeeded'],
            ['forward', 'check', 'succeeded'],
            ['forward', 'update', 'failed'],
            ['forward', 'verify', 'pending'],
            ['compensation', 'update', 'succeeded'],
            ['compensation', 'backup', 'succeeded'],
        ])
        ->and($run->result_summary['failed_step'] ?? null)->toBe('update')
        ->and($run->result_summary['reason'] ?? null)->toBe('el plugin ha fallat');
});

it('fails without rollback when nothing can be undone', function (): void {
    withPipeline([new ScriptedStep('inventory', static fn (): StepOutcome => StepOutcome::failed('sense connexió'))]);
    $run = Run::factory()->create();

    engine()->execute($run);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->steps()->where('kind', RunStepKind::Compensation->value)->count())->toBe(0);
});

it('fails the run when a compensation fails', function (): void {
    withPipeline([
        new ScriptedCompensableStep('backup', ok(), static fn (): StepOutcome => StepOutcome::failed('no es pot restaurar')),
        new ScriptedStep('update', static fn (): StepOutcome => StepOutcome::failed('error')),
    ]);
    $run = Run::factory()->create();

    engine()->execute($run);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->result_summary['reason'] ?? '')->toContain('El rollback ha fallat a «backup»');
});

it('turns exceptions into failed steps', function (): void {
    withPipeline([new ScriptedStep('inventory', static fn (): StepOutcome => throw new RuntimeException('connexió tancada'))]);
    $run = Run::factory()->create();

    engine()->execute($run);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->steps()->value('error'))->toBe('Error inesperat: connexió tancada');
});

it('never stores secrets in summaries, outputs, errors or results', function (): void {
    $secret = 'sup3r-s3cret-db-pass';
    app(SecretRedactor::class)->register($secret);
    withPipeline([
        new ScriptedStep('dump', static fn (): StepOutcome => StepOutcome::succeeded("pass {$secret}", "mysqldump -p{$secret}\n", ['dsn' => "mysql://u:{$secret}@h/db"])),
        new ScriptedStep('fail', static fn (): StepOutcome => throw new RuntimeException("auth failed for {$secret}")),
    ]);
    $run = Run::factory()->create();

    engine()->execute($run);

    $stored = json_encode($run->steps()->get(['summary', 'output', 'error', 'result'])->toArray(), JSON_THROW_ON_ERROR)
        .json_encode($run->fresh()?->result_summary, JSON_THROW_ON_ERROR);
    expect($stored)->not->toContain($secret);
});

it('fails a step that runs out of time and rolls back', function (): void {
    $journal = [];
    withPipeline([
        new ScriptedCompensableStep('backup', ok(), journaling($journal, 'undo backup')),
        new ScriptedStep('update', static function (): StepOutcome {
            sleep(5);

            return StepOutcome::succeeded('massa tard');
        }, timeout: 1),
    ]);
    $run = Run::factory()->create();
    $start = microtime(true);

    engine()->execute($run);

    $update = $run->steps()->where('name', 'update')->firstOrFail();
    expect(microtime(true) - $start)->toBeLessThan(4)
        ->and($update->status)->toBe(RunStepStatus::Failed)
        ->and($update->error)->toBe('Temps esgotat (1 s).')
        ->and($run->status)->toBe(RunStatus::RolledBack)
        ->and($journal)->toBe(['undo backup']);
})->skip(! StepTicker::supported(), 'needs pcntl');

it('fails a step that swallowed its timeout', function (): void {
    withPipeline([new ScriptedStep('update', static function (): StepOutcome {
        try {
            sleep(3);
        } catch (Throwable) { // the ticker's StepTimedOut, swallowed on purpose
            sleep(1);
        }

        return StepOutcome::succeeded('he ignorat el temps');
    }, timeout: 1)]);
    $run = Run::factory()->create();

    engine()->execute($run);

    expect($run->steps()->value('error'))->toBe('Temps esgotat (1 s).')
        ->and($run->status)->toBe(RunStatus::Failed);
});

it('fails the pending step once the run deadline has passed', function (): void {
    $journal = [];
    withPipeline([new ScriptedStep('a', journaling($journal, 'a'))]);
    $run = Run::factory()->create(['deadline_at' => now()->subMinute()]);

    engine()->execute($run);

    expect($journal)->toBe([])
        ->and($run->status)->toBe(RunStatus::Failed)
        ->and($run->steps()->value('error'))->toBe("S'ha superat el termini de l'execució.");
});

it('only runs read-only steps in a dry run and describes the rest', function (): void {
    $journal = [];
    withPipeline([
        new ScriptedReadOnlyStep('inventory', journaling($journal, 'inventory')),
        new ScriptedCompensableStep('update', journaling($journal, 'update'), journaling($journal, 'undo')),
    ]);
    $run = Run::factory()->create(['flags' => ['dry_run' => true]]);

    engine()->execute($run);

    expect($journal)->toBe(['inventory'])
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->result_summary['dry_run'] ?? null)->toBeTrue()
        ->and($run->steps()->where('name', 'update')->value('summary'))->toBe('[dry-run] faria «update»')
        ->and($run->steps()->where('name', 'update')->firstOrFail()->status)->toBe(RunStepStatus::Skipped);
});

it('leaves the run queued while its site is locked', function (): void {
    withPipeline([new ScriptedStep('a', ok())]);
    $run = Run::factory()->create();
    $lease = app(SiteLockManager::class)->acquire($run->site ?? throw new LogicException, 'una altra execució');

    expect(fn () => engine()->execute($run))->toThrow(LockedException::class)
        ->and($run->fresh()?->status)->toBe(RunStatus::Queued)
        ->and(app(RunClaim::class)->isClaimed($run->id))->toBeFalse();

    $lease->release();
});

it('refuses a run that another worker is executing', function (): void {
    withPipeline([new ScriptedStep('a', ok())]);
    $run = Run::factory()->create();
    app(RunClaim::class)->claim($run->id);

    expect(fn () => engine()->execute($run))->toThrow(RunAlreadyClaimed::class);
});

it('stops when it loses its claim, and recovery resumes the run', function (): void {
    $journal = [];
    withPipeline([
        new ScriptedStep('a', journaling($journal, 'a')),
        new ScriptedStep('b', static function () use (&$journal): StepOutcome {
            $journal[] = 'b';
            if (count($journal) === 2) {
                Redis::connection('locks')->command('flushdb'); // claim and lock expire
                sleep(3);
            }

            return StepOutcome::succeeded('b');
        }),
    ]);
    $run = Run::factory()->create();

    expect(fn () => engine()->execute($run))->toThrow(ClaimLost::class)
        ->and($run->fresh()?->status)->toBe(RunStatus::Running);

    expect(app(RunRecovery::class)->sweep())->toBe([$run->id])
        ->and($run->fresh()?->status)->toBe(RunStatus::Interrupted);

    engine()->execute($run);

    $b = $run->steps()->where('name', 'b')->firstOrFail();
    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($journal)->toBe(['a', 'b', 'b'])
        ->and($b->attempts)->toBe(2)
        ->and(AuditLogEntry::query()->where('object_id', (string) $run->id)->pluck('action')->all())
        ->toBe(['run.started', 'run.interrupted', 'run.resumed', 'run.finished']);
})->skip(! StepTicker::supported(), 'needs pcntl');

it('only interrupts active runs that have no claim', function (): void {
    $orphan = Run::factory()->create(['status' => RunStatus::RollingBack]);
    $alive = Run::factory()->create(['status' => RunStatus::Running]);
    $queued = Run::factory()->create(['status' => RunStatus::Queued]);
    app(RunClaim::class)->claim($alive->id);

    expect(app(RunRecovery::class)->sweep())->toBe([$orphan->id])
        ->and($alive->fresh()?->status)->toBe(RunStatus::Running)
        ->and($queued->fresh()?->status)->toBe(RunStatus::Queued);
});

it('rolls back instead of retrying a step interrupted too many times', function (): void {
    $journal = [];
    withPipeline([
        new ScriptedCompensableStep('backup', ok(), journaling($journal, 'undo backup')),
        new ScriptedStep('update', journaling($journal, 'update')),
    ]);
    $run = Run::factory()->create(['status' => RunStatus::Interrupted, 'started_at' => now(), 'deadline_at' => now()->addHour()]);
    $run->steps()->create(['sequence' => 1, 'name' => 'backup', 'status' => RunStepStatus::Succeeded, 'attempts' => 1]);
    $run->steps()->create(['sequence' => 2, 'name' => 'update', 'status' => RunStepStatus::Running, 'attempts' => 2]);

    engine()->execute($run);

    expect($journal)->toBe(['undo backup'])
        ->and($run->status)->toBe(RunStatus::RolledBack)
        ->and($run->steps()->where('name', 'update')->where('kind', 'forward')->value('error'))->toContain('interromput 2 vegades');
});

it('continues an interrupted rollback where it stopped', function (): void {
    $journal = [];
    withPipeline([
        new ScriptedCompensableStep('backup', ok(), journaling($journal, 'undo backup')),
        new ScriptedCompensableStep('update', ok(), journaling($journal, 'undo update')),
    ]);
    $run = Run::factory()->create(['status' => RunStatus::Interrupted, 'started_at' => now()]);
    $run->steps()->create(['sequence' => 1, 'name' => 'backup', 'status' => RunStepStatus::Succeeded, 'attempts' => 1]);
    $run->steps()->create(['sequence' => 2, 'name' => 'update', 'status' => RunStepStatus::Failed, 'attempts' => 1, 'error' => 'fatal nou']);
    $run->steps()->create(['sequence' => 3, 'name' => 'update', 'kind' => RunStepKind::Compensation, 'status' => RunStepStatus::Succeeded, 'attempts' => 1]);
    $run->steps()->create(['sequence' => 4, 'name' => 'backup', 'kind' => RunStepKind::Compensation, 'status' => RunStepStatus::Running, 'attempts' => 1]);

    engine()->execute($run);

    expect($journal)->toBe(['undo backup'])
        ->and($run->status)->toBe(RunStatus::RolledBack)
        ->and($run->result_summary['failed_step'] ?? null)->toBe('update');
});

it('requeues interrupted runs from the watchdog command', function (): void {
    Queue::fake();
    $run = Run::factory()->create(['status' => RunStatus::Running]);

    expect(Artisan::call('engine:recover'))->toBe(0);

    Queue::assertPushed(ExecuteRun::class, static fn (ExecuteRun $job): bool => $job->runId === $run->id);
});

it('keeps the watchdog out of the wpup binary', function (): void {
    expect(app(CliCommandGate::class)->allows('engine:recover'))->toBeFalse();
});
