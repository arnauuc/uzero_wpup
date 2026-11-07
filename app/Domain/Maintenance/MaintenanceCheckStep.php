<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

use App\Domain\Runs\ReadOnlyStep;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;

/**
 * Before anything is written (UZEROWPUP-60): a site that is in maintenance
 * already (the operator's own, or another process's) is not updated at all,
 * whatever its profile says. Read-only, so the run that stops here leaves no
 * restore point and touches nothing. MaintenanceOnStep checks it again right
 * before switching the mode on.
 */
final readonly class MaintenanceCheckStep implements ReadOnlyStep
{
    public const string NAME = 'maintenance-check';

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
        return "Comprovarà que el lloc no és ja en mode manteniment; si ho és, no s'actualitza.";
    }

    public function run(StepContext $context): StepOutcome
    {
        $driver = $this->guard->driver($context->run);
        if (is_string($driver)) {
            return StepOutcome::failed($driver);
        }

        return match ($this->guard->state($driver)) {
            false => StepOutcome::succeeded('El lloc no és en mode manteniment.'),
            true => StepOutcome::failed("El lloc ja és en mode manteniment: no s'actualitza fins que s'acabi. No s'ha tocat res ni s'ha fet cap còpia."),
            null => StepOutcome::failed("No s'ha pogut saber si el lloc ja era en mode manteniment: no s'actualitza. No s'ha tocat res."),
        };
    }
}
