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
use App\Enums\RunStepStatus;
use App\Models\Site;
use Closure;

/**
 * A step of a manual rollback (UZEROWPUP-61), the files or the database,
 * that puts the maintenance mode back as it was before the run when it fails
 * WITHOUT having touched the site (its failure data says `restored: no`): a
 * shop is not left closed for nothing. When the site may be half restored
 * (`unknown` or `yes`), the mode stays on for an operator. Same name, timeout
 * and description as the step it wraps.
 */
final readonly class UntouchedFailureMaintenance implements Step
{
    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     */
    public function __construct(
        private Step $step,
        private RestoreMaintenanceStep $maintenance,
        private Closure $driverFor,
        private RunAuthorization $authorization,
        private SecretRedactor $redactor,
    ) {}

    public function name(): string
    {
        return $this->step->name();
    }

    public function timeoutSeconds(): int
    {
        return $this->step->timeoutSeconds();
    }

    public function describe(StepContext $context): string
    {
        return $this->step->describe($context);
    }

    public function run(StepContext $context): StepOutcome
    {
        $outcome = $this->step->run($context);
        if ($outcome->status !== RunStepStatus::Failed || ($outcome->data['restored'] ?? null) !== 'no') {
            return $outcome;
        }

        $site = $context->run->site;
        $actor = $this->authorization->actorFor($context->run, Ability::RunOperations);
        $note = null;
        if ($site === null || $actor === null) {
            $note = "No s'ha pogut deixar el mode manteniment com estava: revisa'l a mà.";
        } else {
            try {
                $note = $this->maintenance->restoreState(($this->driverFor)($site, $actor), $context, on: false);
            } catch (DriverException $e) {
                $note = "No s'ha pogut deixar el mode manteniment com estava: {$this->redactor->redact($e->getMessage())}";
            }
        }

        return StepOutcome::failed(
            trim($outcome->summary.' '.($note ?? 'Aquest pas no ha tocat el lloc: el mode manteniment torna a estar com abans del rollback.')),
            $outcome->output,
            $outcome->data,
        );
    }
}
