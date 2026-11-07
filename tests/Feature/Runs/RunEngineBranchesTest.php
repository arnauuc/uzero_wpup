<?php

declare(strict_types=1);

use App\Domain\Runs\Exceptions\RunException;
use App\Domain\Runs\OnFailure;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunEngine;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Enums\RunStatus;
use App\Enums\RunStepKind;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Models\Run;
use App\Models\RunStep;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Runs\ScriptedCompensableStep;
use Tests\Support\Runs\ScriptedStep;

/*
 * Engine extensions of UZEROWPUP-60: a pipeline that grows from the results of
 * its own steps, and per-step failure policies (recovery branch, stop).
 */

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    config(['wpup.runs.heartbeat_seconds' => 1]);
});

/**
 * @param  list<string>  $journal
 */
function logged(array &$journal, string $entry, ?StepOutcome $outcome = null): Closure
{
    return static function () use (&$journal, $entry, $outcome): StepOutcome {
        $journal[] = $entry;

        return $outcome ?? StepOutcome::succeeded($entry);
    };
}

/**
 * @return list<array{string, string, string}>
 */
function branchRows(Run $run): array
{
    return array_values($run->steps()->orderBy('sequence')->get()
        ->map(static fn (RunStep $s): array => [$s->kind->value, $s->name, $s->status->value])
        ->all());
}

/**
 * @param  Closure(Run): Pipeline  $factory
 */
function withFactory(Closure $factory): void
{
    app(PipelineResolver::class)->register(RunType::Update, $factory);
}

it('validates the failure policies of a pipeline', function (): void {
    $a = new ScriptedStep('a', static fn (): StepOutcome => StepOutcome::succeeded('ok'));

    expect(fn () => new Pipeline([$a], [], ['b' => OnFailure::Stop]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Pipeline([$a], [], ['a' => OnFailure::Recover]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Pipeline([$a], [$a, $a], ['a' => OnFailure::Recover]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Pipeline([$a], [], [], ['b']))->toThrow(InvalidArgumentException::class)
        ->and((new Pipeline([$a]))->grows())->toBeFalse()
        ->and((new Pipeline([$a], [], [], ['a']))->growsAfter('a'))->toBeTrue()
        ->and((new Pipeline([$a]))->onFailure('a'))->toBe(OnFailure::Compensate);
});

it('grows the pipeline with steps that depend on what the run learnt', function (): void {
    $journal = [];
    withFactory(static function (Run $run) use (&$journal): Pipeline {
        $steps = [new ScriptedStep('plan', static fn (): StepOutcome => StepOutcome::succeeded('pla', '', ['todo' => ['x', 'y']]))];
        $plan = $run->steps()->where('name', 'plan')->where('status', RunStepStatus::Succeeded->value)->first();
        foreach ($plan === null ? [] : array_filter((array) ($plan->result['todo'] ?? []), is_string(...)) as $item) {
            $steps[] = new ScriptedStep("update:{$item}", logged($journal, "update {$item}"));
        }

        return new Pipeline($steps, [], [], ['plan']);
    });
    $run = Run::factory()->create();

    app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($journal)->toBe(['update x', 'update y'])
        ->and(branchRows($run))->toBe([
            ['forward', 'plan', 'succeeded'],
            ['forward', 'update:x', 'succeeded'],
            ['forward', 'update:y', 'succeeded'],
        ]);
});

it('adds the missing steps when a run resumes right after the step they depend on', function (): void {
    $journal = [];
    withFactory(static function (Run $run) use (&$journal): Pipeline {
        $steps = [new ScriptedStep('plan', logged($journal, 'plan'))];
        if ($run->steps()->where('name', 'plan')->where('status', RunStepStatus::Succeeded->value)->exists()) {
            $steps[] = new ScriptedStep('update', logged($journal, 'update'));
        }

        return new Pipeline($steps, [], [], ['plan']);
    });
    $run = Run::factory()->create(['status' => RunStatus::Interrupted, 'started_at' => now()]);
    $run->steps()->create(['sequence' => 1, 'name' => 'plan', 'status' => RunStepStatus::Succeeded, 'attempts' => 1, 'result' => []]);

    app(RunEngine::class)->execute($run);

    expect($journal)->toBe(['update'])
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and(branchRows($run))->toBe([['forward', 'plan', 'succeeded'], ['forward', 'update', 'succeeded']]);
});

it('fails the run when the pipeline would change the steps already recorded', function (): void {
    withFactory(static function (Run $run): Pipeline {
        $ok = static fn (): StepOutcome => StepOutcome::succeeded('ok');
        $ranA = $run->steps()->where('name', 'a')->where('status', RunStepStatus::Succeeded->value)->exists();

        $a = new ScriptedStep('a', $ok);
        $b = new ScriptedStep('b', $ok);

        return new Pipeline($ranA ? [$b, $a] : [$a, $b], [], [], ['a']);
    });
    $run = Run::factory()->create();

    app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failureReason())->toContain('ja no coincideixen')
        ->and(branchRows($run))->toBe([['forward', 'a', 'succeeded'], ['forward', 'b', 'failed']]);
});

it('does not resolve the pipeline again after the steps it does not grow from', function (): void {
    $resolved = 0;
    withFactory(static function () use (&$resolved): Pipeline {
        $resolved++;
        $ok = static fn (): StepOutcome => StepOutcome::succeeded('ok');

        return new Pipeline([new ScriptedStep('plan', $ok), new ScriptedStep('a', $ok), new ScriptedStep('b', $ok)], [], [], ['plan']);
    });

    app(RunEngine::class)->execute(Run::factory()->create());

    // Once to start, once to resume the check at the start, once after the plan.
    expect($resolved)->toBe(3);
});

it('recovers when the pipeline cannot be rebuilt in the middle of the updates', function (Closure $breaks, string $reason): void {
    $journal = [];
    withFactory(static function (Run $run) use (&$journal, $breaks): Pipeline {
        $ranFirst = $run->steps()->where('name', 'update:a')->where('status', RunStepStatus::Succeeded->value)->exists();
        if ($ranFirst) {
            $breaks();
        }

        return new Pipeline(
            [new ScriptedStep('update:a', logged($journal, 'a')), new ScriptedStep('update:b', logged($journal, 'b'))],
            [new ScriptedStep('rollback-files', logged($journal, 'files'))],
            ['update:a' => OnFailure::Recover, 'update:b' => OnFailure::Recover],
            ['update:a'],
        );
    });
    $run = Run::factory()->create();

    app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::RolledBack)
        ->and($journal)->toBe(['a', 'files'])
        ->and($run->result_summary['failed_step'] ?? null)->toBe('update:b')
        ->and($run->failureReason())->toContain($reason)
        ->and(branchRows($run))->toBe([
            ['forward', 'update:a', 'succeeded'],
            ['forward', 'update:b', 'failed'],
            ['recovery', 'rollback-files', 'succeeded'],
        ]);
})->with([
    'a run exception' => [static fn () => throw new RunException('pla il·legible'), 'pla il·legible'],
    'any other error' => [static fn () => throw new LogicException('error inesperat'), 'error inesperat'],
]);

it('fails an interrupted run whose pipeline cannot be rebuilt, instead of leaving it interrupted', function (): void {
    withFactory(static fn (): Pipeline => throw new LogicException('configuració trencada'));
    $run = Run::factory()->create(['status' => RunStatus::Interrupted, 'started_at' => now()]);
    $run->steps()->create(['sequence' => 1, 'name' => 'update:a', 'status' => RunStepStatus::Running, 'attempts' => 1]);

    expect(fn () => app(RunEngine::class)->execute($run))->toThrow(LogicException::class);

    expect($run->fresh()?->status)->toBe(RunStatus::Failed)
        ->and($run->fresh()?->failureReason())->toContain("No s'ha pogut reconstruir l'execució");
});

it('runs the recovery branch in order instead of the compensations', function (): void {
    $journal = [];
    $seen = null;
    $ok = static fn (): StepOutcome => StepOutcome::succeeded('ok');
    withFactory(static function () use (&$journal, &$seen, $ok): Pipeline {
        return new Pipeline(
            [
                new ScriptedCompensableStep('maintenance-on', $ok, logged($journal, 'undo maintenance')),
                new ScriptedStep('update', static fn (): StepOutcome => StepOutcome::failed('fatal nou')),
                new ScriptedStep('restore', logged($journal, 'forward restore')),
            ],
            [
                new ScriptedStep('rollback-files', static function (StepContext $c) use (&$journal, &$seen): StepOutcome {
                    $journal[] = 'files';
                    $seen = [$c->failedStep, $c->failureReason];

                    return StepOutcome::succeeded('fitxers');
                }),
                new ScriptedStep('rollback-database', static fn (): StepOutcome => StepOutcome::skipped('comandes noves')),
                new ScriptedStep('restore', logged($journal, 'recovery restore')),
            ],
            ['update' => OnFailure::Recover],
        );
    });
    $run = Run::factory()->create();

    app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::RolledBack)
        ->and($journal)->toBe(['files', 'recovery restore'])
        ->and($seen)->toBe(['update', 'fatal nou'])
        ->and($run->result_summary['failed_step'] ?? null)->toBe('update')
        ->and(branchRows($run))->toBe([
            ['forward', 'maintenance-on', 'succeeded'],
            ['forward', 'update', 'failed'],
            ['forward', 'restore', 'pending'],
            ['recovery', 'rollback-files', 'succeeded'],
            ['recovery', 'rollback-database', 'skipped'],
            ['recovery', 'restore', 'succeeded'],
        ]);
});

it('stops the recovery branch at its first failure and fails the run', function (): void {
    $journal = [];
    withFactory(static function () use (&$journal): Pipeline {
        return new Pipeline(
            [new ScriptedStep('update', static fn (): StepOutcome => StepOutcome::failed('fatal nou'))],
            [
                new ScriptedStep('rollback-files', static fn (): StepOutcome => StepOutcome::failed('còpia corrupta')),
                new ScriptedStep('restore', logged($journal, 'restore')),
            ],
            ['update' => OnFailure::Recover],
        );
    });
    $run = Run::factory()->create();

    app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($journal)->toBe([])
        ->and($run->failureReason())->toContain('El rollback ha fallat a «rollback-files»: còpia corrupta')
        ->and(branchRows($run))->toBe([
            ['forward', 'update', 'failed'],
            ['recovery', 'rollback-files', 'failed'],
            ['recovery', 'restore', 'pending'],
        ]);
});

it('compensates a failure outside the recovery range as usual', function (): void {
    $journal = [];
    withFactory(static function () use (&$journal): Pipeline {
        return new Pipeline(
            [
                new ScriptedCompensableStep('maintenance-on', static fn (): StepOutcome => StepOutcome::failed('no es pot activar'), logged($journal, 'undo maintenance')),
                new ScriptedStep('update', logged($journal, 'update')),
            ],
            [new ScriptedStep('rollback-files', logged($journal, 'files'))],
            ['update' => OnFailure::Recover],
        );
    });
    $run = Run::factory()->create();

    app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::RolledBack)
        ->and($journal)->toBe(['undo maintenance'])
        ->and(branchRows($run))->toBe([
            ['forward', 'maintenance-on', 'failed'],
            ['forward', 'update', 'pending'],
            ['compensation', 'maintenance-on', 'succeeded'],
        ]);
});

it('neither compensates nor recovers a step that stops the run', function (): void {
    $journal = [];
    withFactory(static function () use (&$journal): Pipeline {
        return new Pipeline(
            [
                new ScriptedCompensableStep('maintenance-on', logged($journal, 'on'), logged($journal, 'undo maintenance')),
                new ScriptedStep('maintenance-restore', static fn (): StepOutcome => StepOutcome::failed('no es pot desactivar')),
            ],
            [new ScriptedStep('rollback-files', logged($journal, 'files'))],
            ['maintenance-restore' => OnFailure::Stop],
        );
    });
    $run = Run::factory()->create();

    app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($journal)->toBe(['on'])
        ->and($run->failureReason())->toContain('no es pot desactivar')
        ->and($run->steps()->where('kind', '!=', RunStepKind::Forward->value)->count())->toBe(0);
});

it('resumes an interrupted recovery where it stopped', function (): void {
    $journal = [];
    $step = static function (string $name) use (&$journal): Step {
        return new ScriptedStep($name, logged($journal, $name));
    };
    withFactory(static function () use ($step): Pipeline {
        return new Pipeline(
            [$step('update')],
            [$step('rollback-files'), $step('rollback-database'), $step('restore')],
            ['update' => OnFailure::Recover],
        );
    });
    $run = Run::factory()->create(['status' => RunStatus::Interrupted, 'started_at' => now()]);
    $run->steps()->create(['sequence' => 1, 'name' => 'update', 'status' => RunStepStatus::Failed, 'attempts' => 1, 'error' => 'fatal nou']);
    $run->steps()->create(['sequence' => 2, 'name' => 'rollback-files', 'kind' => RunStepKind::Recovery, 'status' => RunStepStatus::Succeeded, 'attempts' => 1]);
    $run->steps()->create(['sequence' => 3, 'name' => 'rollback-database', 'kind' => RunStepKind::Recovery, 'status' => RunStepStatus::Running, 'attempts' => 1]);
    $run->steps()->create(['sequence' => 4, 'name' => 'restore', 'kind' => RunStepKind::Recovery, 'status' => RunStepStatus::Pending]);

    app(RunEngine::class)->execute($run);

    expect($journal)->toBe(['rollback-database', 'restore'])
        ->and($run->status)->toBe(RunStatus::RolledBack)
        ->and($run->result_summary['failed_step'] ?? null)->toBe('update');
});

it('starts the recovery of a run interrupted between the failure and the branch', function (): void {
    $journal = [];
    withFactory(static function () use (&$journal): Pipeline {
        return new Pipeline(
            [new ScriptedStep('update', logged($journal, 'update'))],
            [new ScriptedStep('rollback-files', logged($journal, 'files'))],
            ['update' => OnFailure::Recover],
        );
    });
    $run = Run::factory()->create(['status' => RunStatus::Interrupted, 'started_at' => now()]);
    $run->steps()->create(['sequence' => 1, 'name' => 'update', 'status' => RunStepStatus::Failed, 'attempts' => 1, 'error' => 'fatal nou']);

    app(RunEngine::class)->execute($run);

    expect($journal)->toBe(['files'])
        ->and($run->status)->toBe(RunStatus::RolledBack);
});
