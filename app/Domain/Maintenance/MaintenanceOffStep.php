<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;

/**
 * Switches the maintenance mode off right before the verification
 * (UZEROWPUP-53), so that the verifier sees the real site and not the
 * maintenance page. No bypass is built for it (no mu-plugin, no token): the
 * window of the real site without maintenance is the verification, and if it
 * fails the rollback steps switch the mode on again. F4 may revisit this
 * (bypass token) if the window proves too long.
 *
 * It only touches what this run switched on; when the profile asks for no
 * maintenance (the On step was skipped) it is a no-op. Idempotent.
 */
final readonly class MaintenanceOffStep implements Step
{
    public const string NAME = 'maintenance-off';

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
        return "Desactivarà el mode manteniment (si l'havia activat l'execució) perquè la verificació vegi el lloc real.";
    }

    public function run(StepContext $context): StepOutcome
    {
        return MaintenanceRestoreStep::restore($this->guard, $context, "L'execució no ha tocat el mode manteniment.");
    }
}
