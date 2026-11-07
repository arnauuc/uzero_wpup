<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;

/**
 * First step of the update's own rollback (UZEROWPUP-60): the maintenance
 * mode is switched on ALWAYS, whatever the profile says, so a shop is closed
 * while its files and database go back (AGENTS.md rule 4). The state before
 * the run is remembered first when the run never stored it (a profile without
 * maintenance), so MaintenanceRestoreStep, the last step of the rollback,
 * leaves the mode as it was.
 *
 * If the mode cannot be switched on, the step still succeeds with a warning:
 * the files rollback, always safe, must not be blocked by it, and the
 * database rollback switches it on itself and does not restore anything when
 * it cannot. Idempotent. The files mirror never touches `.maintenance`
 * (SiteDriver::restoreFiles()), so the mode stays on through the whole
 * rollback, also when the worker dies halfway through it (UZEROWPUP-62).
 */
final readonly class MaintenanceForceOnStep implements Step
{
    public const string NAME = 'rollback-maintenance-on';

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
        return 'Activarà el mode manteniment, sigui quin sigui el perfil, abans de desfer els fitxers i la base de dades.';
    }

    public function run(StepContext $context): StepOutcome
    {
        $run = $context->run;
        $driver = $this->guard->driver($run);
        if (is_string($driver)) {
            return StepOutcome::succeeded("No s'ha pogut activar el mode manteniment: {$driver} La base de dades no es restaurarà si no s'activa.", $driver);
        }

        if ($this->guard->initial($run) === null) {
            // The check before the backup saw it off; unknown now is taken as off, so the rollback ends with it off.
            $this->guard->remember($run, $this->guard->state($driver) === true);
        }
        $failure = $this->guard->switchTo($driver, true, $run->id);
        if ($failure !== null) {
            return StepOutcome::succeeded("{$failure} La base de dades no es restaurarà si no s'activa.", $failure);
        }

        return StepOutcome::succeeded('Mode manteniment activat per al rollback.');
    }
}
