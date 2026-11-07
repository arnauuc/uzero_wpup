<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Maintenance\MaintenanceGuard;
use App\Domain\Maintenance\MaintenanceOnStep;
use App\Domain\Maintenance\MaintenanceSteps;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\RunEngine;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\RunStatus;
use App\Enums\RunStepKind;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Models\Run;
use App\Models\Site;
use App\Models\SiteProfile;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeDriver;
use Tests\Support\Runs\ScriptedCompensableStep;
use Tests\Support\Runs\ScriptedStep;

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    config(['wpup.runs.heartbeat_seconds' => 1]);
    app(SecretRedactor::class)->reset();
});

function maintenanceGuard(FakeDriver $driver, bool $defaultOn = true): MaintenanceGuard
{
    return new MaintenanceGuard(
        static fn (Site $s, AuditActor $a): SiteDriver => $driver,
        app(RunAuthorization::class), app(SecretRedactor::class), $defaultOn, 60,
    );
}

/**
 * @param  bool|null  $profile  the profile's maintenance_mode (null: the default)
 */
function maintenanceRun(?bool $profile = null): Run
{
    $site = Site::factory()->create();
    SiteProfile::factory()->create(['site_id' => $site->id, 'maintenance_mode' => $profile]);

    return Run::factory()->create(['site_id' => $site->id, 'type' => RunType::Update]);
}

/**
 * The whole flow of UZEROWPUP-60 in miniature: on, update, off, verify, restore. The scripted
 * steps note whether the site was in maintenance when they ran.
 *
 * @param  list<bool>  $seen
 * @param  list<Step>  $extra  steps between verify and restore (a rollback)
 */
function maintenancePipeline(FakeDriver $driver, array &$seen, ?StepOutcome $update = null, array $extra = [], bool $defaultOn = true): void
{
    $steps = new MaintenanceSteps(maintenanceGuard($driver, $defaultOn), 60);
    $note = static function (?StepOutcome $outcome = null) use (&$seen, $driver): Closure {
        return static function () use (&$seen, $driver, $outcome): StepOutcome {
            $seen[] = $driver->maintenance;

            return $outcome ?? StepOutcome::succeeded('fet');
        };
    };
    app(PipelineResolver::class)->register(RunType::Update, static fn (): Pipeline => new Pipeline([
        $steps->on(),
        new ScriptedCompensableStep('update', $note($update), static fn (): StepOutcome => StepOutcome::succeeded('desfet')),
        $steps->off(),
        new ScriptedStep('verify', $note()),
        ...$extra,
        $steps->restore(),
    ]));
}

it('switches maintenance on for the update and off for the verification', function (): void {
    $driver = new FakeDriver;
    $seen = [];
    maintenancePipeline($driver, $seen);
    $run = maintenanceRun();

    app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($seen)->toBe([true, false])
        ->and($driver->maintenance)->toBeFalse()
        ->and($run->steps()->where('status', '!=', RunStepStatus::Succeeded->value)->count())->toBe(0);
});

it('does not update a site that is in maintenance already, and never touches its maintenance file', function (): void {
    $driver = new FakeDriver;
    $driver->maintenance = true;
    $seen = [];
    maintenancePipeline($driver, $seen);
    $run = maintenanceRun();

    app(RunEngine::class)->execute($run);

    // The step has two rows: the forward one, and its compensation (which finds nothing to
    // restore). Read each by kind: without ORDER BY, PostgreSQL may return either first.
    $on = $run->steps()->where('name', 'maintenance-on')->where('kind', RunStepKind::Forward->value)->sole();
    $undo = $run->steps()->where('name', 'maintenance-on')->where('kind', RunStepKind::Compensation->value)->sole();
    expect($run->status)->not->toBe(RunStatus::Succeeded)
        ->and($on->status)->toBe(RunStepStatus::Failed)
        ->and($undo->status)->toBe(RunStepStatus::Skipped)
        ->and($on->error)->toContain("El lloc ja és en mode manteniment: no s'actualitza fins que s'acabi")
        ->and($seen)->toBe([])
        ->and($driver->maintenance)->toBeTrue()
        ->and($driver->writeCalls)->not->toContain('setMaintenanceMode')
        ->and($run->fresh()?->flags)->not->toHaveKey(MaintenanceGuard::FLAG);
});

it('restores the initial state when an update fails, through the compensation', function (): void {
    $driver = new FakeDriver;
    $seen = [];
    maintenancePipeline($driver, $seen, StepOutcome::failed('el plugin ha fallat'));
    $run = maintenanceRun();

    app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::RolledBack)
        ->and($seen)->toBe([true])
        ->and($driver->maintenance)->toBeFalse();
});

it('ends with maintenance off after a rollback switched it on', function (): void {
    $driver = new FakeDriver;
    $seen = [];
    // The files rollback switches it on again before the database rollback (UZEROWPUP-57).
    $rollback = new ScriptedStep('rollback', static function () use ($driver): StepOutcome {
        $driver->maintenance = true;

        return StepOutcome::succeeded('revertit');
    });
    maintenancePipeline($driver, $seen, extra: [$rollback]);

    app(RunEngine::class)->execute(maintenanceRun());

    expect($seen)->toBe([true, false])
        ->and($driver->maintenance)->toBeFalse();
});

it('does nothing when the profile asks for no maintenance', function (): void {
    $driver = new FakeDriver;
    $seen = [];
    maintenancePipeline($driver, $seen);
    $run = maintenanceRun(false);

    app(RunEngine::class)->execute($run);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($seen)->toBe([false, false])
        ->and($driver->writeCalls)->not->toContain('setMaintenanceMode')
        ->and($run->steps()->where('name', 'maintenance-on')->value('status'))->toBe(RunStepStatus::Skipped)
        ->and($run->steps()->where('name', 'maintenance-restore')->value('status'))->toBe(RunStepStatus::Skipped);
});

it('uses the configured default when the profile does not choose', function (): void {
    $driver = new FakeDriver;
    $seen = [];
    maintenancePipeline($driver, $seen, defaultOn: false);

    app(RunEngine::class)->execute(maintenanceRun());

    expect($seen)->toBe([false, false])->and($driver->writeCalls)->not->toContain('setMaintenanceMode');
});

it('does not take the resumed state for the initial one after a worker died with maintenance on', function (): void {
    $driver = new FakeDriver;
    $steps = new MaintenanceSteps(maintenanceGuard($driver), 60);
    $run = maintenanceRun();
    $context = new StepContext($run, false);

    expect($steps->on()->run($context)->data)->toBe(['was_active' => false]);
    // The worker died here: the site is in maintenance, the step was not recorded. Resumed:
    $resumed = $steps->on()->run(new StepContext($run->fresh() ?? $run, false));
    expect($resumed->data)->toBe(['was_active' => false])
        ->and($driver->maintenance)->toBeTrue();

    $steps->off()->run(new StepContext($run->fresh() ?? $run, false));
    expect($driver->maintenance)->toBeFalse();
});

it('is idempotent when run twice at the end', function (): void {
    $driver = new FakeDriver;
    $steps = new MaintenanceSteps(maintenanceGuard($driver), 60);
    $run = maintenanceRun();
    $steps->on()->run(new StepContext($run, false));

    foreach ([$steps->off(), $steps->restore(), $steps->restore()] as $step) {
        expect($step->run(new StepContext($run->fresh() ?? $run, false))->status)->toBe(RunStepStatus::Succeeded);
    }
    expect($driver->maintenance)->toBeFalse();
});

it('fails the update before it starts when the site state cannot be read', function (): void {
    $driver = new FakeDriver;
    $driver->wpFailure = new ConnectionFailed('sense xarxa');
    $run = maintenanceRun();

    $outcome = (new MaintenanceSteps(maintenanceGuard($driver), 60))->on()->run(new StepContext($run, false));

    expect($outcome->status)->toBe(RunStepStatus::Failed)
        ->and($outcome->summary)->toContain('no s\'actualitza')
        ->and($run->fresh()?->flags)->not->toHaveKey(MaintenanceGuard::FLAG);
});

it('has nothing to restore when the on step never ran', function (): void {
    $driver = new FakeDriver;
    $driver->maintenance = true;

    $outcome = (new MaintenanceOnStep(maintenanceGuard($driver), 60))->compensate(new StepContext(maintenanceRun(), false));

    expect($outcome->status)->toBe(RunStepStatus::Skipped)->and($driver->maintenance)->toBeTrue();
});

it('refreshes the .maintenance file only when the run manages the mode and it is on', function (): void {
    $driver = new FakeDriver;
    $guard = maintenanceGuard($driver);
    $run = maintenanceRun();

    $driver->maintenance = true;
    $guard->refresh($driver, $run);
    expect($driver->wpCalls)->not->toContain(['maintenance-mode', 'activate', '--force', '--skip-plugins', '--skip-themes']);

    $guard->remember($run, false);
    $guard->refresh($driver, $run);
    expect($driver->wpCalls)->toContain(['maintenance-mode', 'activate', '--force', '--skip-plugins', '--skip-themes']);
});
