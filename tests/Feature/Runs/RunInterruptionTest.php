<?php

declare(strict_types=1);

use App\Domain\Runs\InterruptionDecision;
use App\Domain\Runs\OnFailure;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunEngine;
use App\Domain\Runs\RunInterruption;
use App\Domain\Runs\StepOutcome;
use App\Enums\AuditResult;
use App\Enums\RunStatus;
use App\Enums\RunStepKind;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Models\AuditLogEntry;
use App\Models\Run;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Runs\ScriptedStep;

/*
 * UZEROWPUP-62: every interruption is recorded on the run and in audit_log,
 * with the step and the decision, and a recorded step the code no longer has
 * fails the run instead of leaving it to be retried forever.
 */

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    config(['wpup.runs.heartbeat_seconds' => 1, 'wpup.runs.max_step_attempts' => 2]);
});

/**
 * The update a, b, recovering with rollback, restore.
 */
function interruptionPipeline(bool $withRollback = true): void
{
    $ok = static fn (string $name): ScriptedStep => new ScriptedStep($name, static fn (): StepOutcome => StepOutcome::succeeded($name));
    app(PipelineResolver::class)->register(RunType::Update, static fn (): Pipeline => new Pipeline(
        [$ok('a'), $ok('b')],
        $withRollback ? [$ok('rollback'), $ok('restore')] : [$ok('restore')],
        ['a' => OnFailure::Recover, 'b' => OnFailure::Recover],
    ));
}

/**
 * @param  list<array{string, RunStepStatus, int, 3?: RunStepKind}>  $rows  name, status, attempts, kind
 */
function interruptedRun(array $rows): Run
{
    $run = Run::factory()->create(['status' => RunStatus::Interrupted, 'started_at' => now(), 'deadline_at' => now()->addHour()]);
    foreach ($rows as $i => $row) {
        $run->steps()->create(['sequence' => $i + 1, 'name' => $row[0], 'status' => $row[1], 'attempts' => $row[2], 'kind' => $row[3] ?? RunStepKind::Forward,
            'error' => $row[1] === RunStepStatus::Failed ? 'fatal nou' : null]);
    }

    return $run;
}

/**
 * @return list<array{string, string|null, string|null, int, string}>
 */
function interruptionsOf(Run $run): array
{
    return array_map(static fn (RunInterruption $i): array => [$i->phase, $i->step, $i->kind?->value, $i->attempts, $i->decision->value], RunInterruption::of($run->fresh() ?? $run));
}

it('records the step and the decision of every interruption, on the run and in audit_log', function (array $rows, array $expected, RunStatus $final): void {
    /** @var list<array{string, RunStepStatus, int, 3?: RunStepKind}> $rows */
    interruptionPipeline();
    $run = interruptedRun($rows);

    app(RunEngine::class)->execute($run);

    $audit = AuditLogEntry::query()->where('object_id', (string) $run->id)->where('action', 'run.resumed')->sole();
    expect($run->status)->toBe($final)
        ->and(interruptionsOf($run))->toBe([$expected])
        ->and($audit->context['step'] ?? null)->toBe($expected[1])
        ->and($audit->context['decision'] ?? null)->toBe($expected[4])
        ->and($audit->result)->toBe($expected[4] === InterruptionDecision::GiveUp->value ? AuditResult::Failure : AuditResult::Success);
})->with([
    'during a step' => [[['a', RunStepStatus::Running, 1]], ['running', 'a', 'forward', 1, 'retry_step'], RunStatus::Succeeded],
    'between steps' => [[['a', RunStepStatus::Succeeded, 1], ['b', RunStepStatus::Pending, 0]], ['running', 'b', 'forward', 0, 'next_step'], RunStatus::Succeeded],
    'too many times' => [[['a', RunStepStatus::Succeeded, 1], ['b', RunStepStatus::Running, 2]], ['running', 'b', 'forward', 2, 'give_up'], RunStatus::RolledBack],
    'during the rollback' => [[['a', RunStepStatus::Failed, 1], ['b', RunStepStatus::Pending, 0], ['rollback', RunStepStatus::Running, 1, RunStepKind::Recovery], ['restore', RunStepStatus::Pending, 0, RunStepKind::Recovery]],
        ['rolling_back', 'rollback', 'recovery', 1, 'retry_step'], RunStatus::RolledBack],
]);

it('keeps every interruption of the same run', function (): void {
    interruptionPipeline();
    $run = interruptedRun([['a', RunStepStatus::Succeeded, 1], ['b', RunStepStatus::Pending, 0]]);
    $run->forceFill(['interruptions' => [(new RunInterruption(now()->toImmutable(), 'running', 'a', RunStepKind::Forward, 1, InterruptionDecision::RetryStep))->toArray()]])->save();

    app(RunEngine::class)->execute($run);

    expect(array_column(interruptionsOf($run), 1))->toBe(['a', 'b'])
        ->and(RunInterruption::of($run)[1]->describe())->toContain('al pas «b»')->toContain('es continua amb el pas següent');
});

it('records an interrupted run that cannot be rebuilt as failed', function (): void {
    app(PipelineResolver::class)->register(RunType::Update, static fn (): Pipeline => throw new LogicException('configuració trencada'));
    $run = interruptedRun([['a', RunStepStatus::Running, 1]]);

    expect(fn () => app(RunEngine::class)->execute($run))->toThrow(LogicException::class);

    expect($run->fresh()?->status)->toBe(RunStatus::Failed)
        ->and(interruptionsOf($run))->toBe([['running', 'a', 'forward', 1, 'fail']]);
});

it('fails, instead of retrying forever, a rollback whose recorded step the code no longer has', function (): void {
    // Deployed between the interruption and the resume: the recovery branch lost «rollback».
    interruptionPipeline(withRollback: false);
    $run = interruptedRun([['a', RunStepStatus::Failed, 1], ['b', RunStepStatus::Pending, 0], ['rollback', RunStepStatus::Running, 1, RunStepKind::Recovery], ['restore', RunStepStatus::Pending, 0, RunStepKind::Recovery]]);

    app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->result_summary['failed_step'] ?? null)->toBe('rollback')
        ->and($run->failureReason())->toContain('ja no existeix')
        ->and(interruptionsOf($run))->toBe([['rolling_back', 'rollback', 'recovery', 1, 'retry_step']]);
});
