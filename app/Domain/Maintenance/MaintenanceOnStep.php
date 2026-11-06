<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

use App\Domain\Runs\CompensableStep;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;

/**
 * Switches the maintenance mode on before the updates (UZEROWPUP-53), when the
 * site's profile asks for it (skipped otherwise). The state before is stored
 * in the run first, so the step is idempotent and a resumed run still knows
 * it. If the mode cannot be switched on the step fails: the update does not
 * start without the maintenance the profile asked for. A site that is in
 * maintenance already (the operator's own, or another process's) is not
 * updated at all: the step fails with a clear reason and touches nothing, so
 * the operator's `.maintenance` is never overwritten or switched off, and the
 * verifier never meets a maintenance page it did not expect.
 *
 * Compensation (the run failed): the mode goes back to the state before. A
 * pipeline that rolls back by itself (UZEROWPUP-60) does it with forward steps
 * and ends with MaintenanceRestoreStep, so the mode stays on during the
 * rollback.
 */
final readonly class MaintenanceOnStep implements CompensableStep
{
    public const string NAME = 'maintenance-on';

    public function __construct(private MaintenanceGuard $guard, private int $timeoutSeconds) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function describe(StepContext $context): string
    {
        return "Activarà el mode manteniment abans d'actualitzar (si el perfil del lloc ho demana) i desarà com estava per deixar-lo igual en acabar.";
    }

    public function run(StepContext $context): StepOutcome
    {
        $run = $context->run;
        if ($run->site === null) {
            return StepOutcome::failed("L'execució no té cap lloc.");
        }
        if (! $this->guard->enabledFor($run->site)) {
            return StepOutcome::skipped('El perfil del lloc no demana mode manteniment.');
        }
        $driver = $this->guard->driver($run);
        if (is_string($driver)) {
            return StepOutcome::failed($driver);
        }

        // Resumed after the mode was switched on by this run: the stored state is the initial one.
        if ($this->guard->initial($run) === null) {
            $state = $this->guard->state($driver);
            if ($state === null) {
                return StepOutcome::failed("No s'ha pogut saber si el lloc ja era en mode manteniment: no s'actualitza.");
            }
            if ($state) {
                // Never overwritten, never switched off or back on afterwards: nothing is remembered.
                return StepOutcome::failed("El lloc ja és en mode manteniment: no s'actualitza fins que s'acabi.");
            }
            $this->guard->remember($run, false);
        }
        $failure = $this->guard->switchTo($driver, true, $run->id);
        if ($failure !== null) {
            return StepOutcome::failed($failure);
        }

        return StepOutcome::succeeded('Mode manteniment activat.', '', ['was_active' => false]);
    }

    public function compensate(StepContext $context): StepOutcome
    {
        return MaintenanceRestoreStep::restore($this->guard, $context, "No s'havia tocat el mode manteniment.");
    }
}
