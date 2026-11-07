<?php

declare(strict_types=1);

namespace App\Domain\Rollback;

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\RestoreMaintenanceStep;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Identity\Ability;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretRedactor;
use App\Models\Site;
use Closure;

/**
 * Last step of a manual rollback (UZEROWPUP-61): leaves the maintenance mode
 * as it was before the run (RestoreMaintenanceStep switched it on, and the
 * files rollback kept it on). Only reached when the files and the database
 * steps succeeded: a rollback that failed half way leaves the site in
 * maintenance for an operator. If the mode cannot be put back the step
 * fails, so the operator sees that the site is still closed; the rollback
 * itself is done and is not undone.
 */
final readonly class RollbackMaintenanceRestoreStep implements Step
{
    public const string NAME = 'maintenance-restore';

    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     */
    public function __construct(
        private RestoreMaintenanceStep $maintenance,
        private Closure $driverFor,
        private RunAuthorization $authorization,
        private SecretRedactor $redactor,
        private int $timeoutSeconds,
    ) {}

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
        return 'Deixarà el mode manteniment com estava abans del rollback.';
    }

    public function run(StepContext $context): StepOutcome
    {
        $site = $context->run->site;
        $actor = $this->authorization->actorFor($context->run, Ability::RunOperations);
        if ($site === null || $actor === null) {
            return StepOutcome::failed($site === null ? "L'execució no té cap lloc." : RunAuthorization::deniedMessage($context->run));
        }

        try {
            $driver = ($this->driverFor)($site, $actor);
        } catch (DriverException $e) {
            return StepOutcome::failed("Rollback fet, però no s'ha pogut connectar per deixar el mode manteniment com estava: {$this->redactor->redact($e->getMessage())}");
        }
        $warning = $this->maintenance->restoreState($driver, $context, on: false);
        if ($warning !== null) {
            return StepOutcome::failed("Rollback fet, però el mode manteniment no s'ha pogut deixar com estava: {$warning}");
        }

        return StepOutcome::succeeded('Mode manteniment com abans del rollback.');
    }
}
