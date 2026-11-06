<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;

/**
 * The last step of the update flow (UZEROWPUP-53): leaves the maintenance mode
 * as it was before the run, whatever happened in between (updated, verified,
 * rolled back by the pipeline's own steps). It is what guarantees that a
 * rollback, which switches the mode on, does not leave the site in
 * maintenance. If the run fails before reaching it, MaintenanceOnStep's
 * compensation does the same. Idempotent.
 */
final readonly class MaintenanceRestoreStep implements Step
{
    public const string NAME = 'maintenance-restore';

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
        return "Deixarà el mode manteniment com estava abans de l'execució.";
    }

    public function run(StepContext $context): StepOutcome
    {
        return self::restore($this->guard, $context, "No s'havia tocat el mode manteniment.");
    }

    /**
     * Puts the mode back as it was before the run.
     */
    public static function restore(MaintenanceGuard $guard, StepContext $context, string $untouched): StepOutcome
    {
        $initial = $guard->initial($context->run);
        if ($initial === null) {
            return StepOutcome::skipped($untouched);
        }
        $driver = $guard->driver($context->run);
        if (is_string($driver)) {
            return StepOutcome::failed("No s'ha pogut deixar el mode manteniment com estava: {$driver}");
        }
        $failure = $guard->switchTo($driver, $initial, $context->run->id);
        if ($failure !== null) {
            return StepOutcome::failed($failure);
        }

        return StepOutcome::succeeded($initial ? 'El lloc ja era en mode manteniment: es deixa així.' : 'Mode manteniment desactivat.', '', ['active' => $initial]);
    }
}
