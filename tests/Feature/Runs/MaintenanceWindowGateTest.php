<?php

declare(strict_types=1);

use App\Domain\Runs\Concurrency\OutsideMaintenanceWindow;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunClaim;
use App\Domain\Runs\RunEngine;
use App\Domain\Runs\StepOutcome;
use App\Enums\RunStatus;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Jobs\ExecuteRun;
use App\Models\Run;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Redis;
use Mockery\MockInterface;
use Tests\Support\Runs\ScriptedStep;

/*
 * ADR-0006: an update starts only inside its site's maintenance window, with
 * enough of it left (UZEROWPUP-60).
 */

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    config([
        'wpup.updates.window.enforced' => true,
        'wpup.updates.window.minimum_minutes' => 15,
        'wpup.sites.default_window' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '01:00', 'end' => '06:00'],
    ]);
});

/**
 * @param  list<string>  $journal
 */
function windowPipeline(array &$journal): void
{
    app(PipelineResolver::class)->register(RunType::Update, static function () use (&$journal): Pipeline {
        return new Pipeline([new ScriptedStep('a', static function () use (&$journal): StepOutcome {
            $journal[] = 'a';

            return StepOutcome::succeeded('fet');
        })]);
    });
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function at(string $moment): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($moment, 'Europe/Madrid'));
}

it('starts an update inside the window of its site', function (): void {
    $journal = [];
    windowPipeline($journal);
    at('2026-11-02 03:00');
    $run = Run::factory()->create();

    app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($journal)->toBe(['a']);
});

it('keeps an update queued outside its window, without touching anything', function (): void {
    $journal = [];
    windowPipeline($journal);
    at('2026-11-02 12:00');
    $run = Run::factory()->create();

    try {
        app(RunEngine::class)->execute($run);
        throw new LogicException('The run should wait for its window.');
    } catch (OutsideMaintenanceWindow $e) {
        expect($e->opensAt->format('Y-m-d H:i'))->toBe('2026-11-03 01:00');
    }

    expect($run->fresh()?->status)->toBe(RunStatus::Queued)
        ->and($run->steps()->count())->toBe(0)
        ->and($journal)->toBe([])
        ->and(app(RunClaim::class)->isClaimed($run->id))->toBeFalse();
});

it('does not start when too little of the window is left', function (): void {
    $journal = [];
    windowPipeline($journal);
    at('2026-11-02 05:50');
    $run = Run::factory()->create();

    expect(fn () => app(RunEngine::class)->execute($run))->toThrow(OutsideMaintenanceWindow::class)
        ->and($journal)->toBe([]);
});

it('uses the window of the site rather than the default one', function (): void {
    $journal = [];
    windowPipeline($journal);
    at('2026-11-02 11:00');
    $day = Run::factory()->for(Site::factory()->state(['maintenance_window' => ['days' => [1, 2, 3, 4, 5], 'start' => '10:00', 'end' => '13:00']]))->create();
    $night = Run::factory()->create();

    app(RunEngine::class)->execute($day);

    expect($day->status)->toBe(RunStatus::Succeeded)
        ->and(fn () => app(RunEngine::class)->execute($night))->toThrow(OutsideMaintenanceWindow::class);
});

it('lets an admin --now run start outside the window', function (): void {
    $journal = [];
    windowPipeline($journal);
    at('2026-11-02 12:00');
    $run = Run::factory()->create(['flags' => ['now' => true]]);

    app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::Succeeded);
});

it('resumes an interrupted update whatever the time', function (): void {
    $journal = [];
    windowPipeline($journal);
    at('2026-11-02 12:00');
    $run = Run::factory()->create(['status' => RunStatus::Interrupted, 'started_at' => now()]);
    $run->steps()->create(['sequence' => 1, 'name' => 'a', 'status' => RunStepStatus::Running, 'attempts' => 1]);

    app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($journal)->toBe(['a']);
});

it('only gates the runs that change the code of a site', function (): void {
    $journal = [];
    windowPipeline($journal);
    at('2026-11-02 12:00');
    app(PipelineResolver::class)->register(RunType::Inventory, static fn (): Pipeline => new Pipeline([new ScriptedStep('inventory', static fn (): StepOutcome => StepOutcome::succeeded('ok'))]));
    $run = Run::factory()->create(['type' => RunType::Inventory]);

    app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::Succeeded);
});

it('fails a queued update whose site has a malformed window instead of waiting forever', function (): void {
    $journal = [];
    windowPipeline($journal);
    at('2026-11-02 03:00');
    $run = Run::factory()->for(Site::factory()->state(['maintenance_window' => ['days' => [], 'start' => '01:00', 'end' => '06:00']]))->create();

    app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failureReason())->toContain('finestra de manteniment')
        ->and($journal)->toBe([]);
});

it('fails a queued update whose window is too short to ever start one, instead of waiting forever', function (): void {
    $journal = [];
    windowPipeline($journal);
    at('2026-11-02 03:00');
    $run = Run::factory()->for(Site::factory()->state(['maintenance_window' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '02:55', 'end' => '03:05']]))->create();

    app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failureReason())->toContain('dura menys dels 15 minuts')
        ->and($journal)->toBe([]);
});

it('releases the job until the window opens, without failing it', function (): void {
    $journal = [];
    windowPipeline($journal);
    at('2026-11-02 05:50');
    config(['wpup.updates.window.recheck_seconds' => 900]);
    $run = Run::factory()->create();
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('release')->once()->with(900);
    $job->shouldReceive('isReleased', 'isDeletedOrReleased', 'hasFailed')->andReturn(false);
    $execute = new ExecuteRun($run->id);
    $execute->setJob($job);

    app()->call([$execute, 'handle']);

    expect($run->fresh()?->status)->toBe(RunStatus::Queued);
});
