<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretRedactor;
use App\Models\Site;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Maintenance mode for a restore (UZEROWPUP-46), switched on right after the
 * copies are checked and before the point of the current state is made, so
 * that no order enters between that point and the restore. RestoreStep
 * switches it on again after mirroring the files (the mirror removes
 * `.maintenance`) and leaves it as it was before the run at the end, also
 * when it fails; PreRestoreBackupStep does it when the point cannot be made.
 * It is not a compensable step on purpose: a failed restore must end as
 * failed, never as rolled back. A site where it cannot be switched on
 * (broken) is restored anyway, with a warning.
 */
final readonly class RestoreMaintenanceStep implements Step
{
    public const string NAME = 'maintenance';

    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     */
    public function __construct(
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
        return "Activarà el mode manteniment (si no ho estava) abans del punt de l'estat actual i de la restauració, i en acabar el deixarà com estava.";
    }

    public function run(StepContext $context): StepOutcome
    {
        $actor = RestoreCheckStep::actor($this->authorization, $context->run);
        $site = $context->run->site;
        if (is_string($actor) || $site === null) {
            return StepOutcome::failed(is_string($actor) ? $actor : "L'execució no té cap lloc.");
        }

        try {
            $driver = ($this->driverFor)($site, $actor);
            $wasActive = self::isActive($driver);
            if (! $wasActive) {
                $driver->setMaintenanceMode(true);
            }
        } catch (DriverException $e) {
            $reason = $this->redactor->redact($e->getMessage());

            return StepOutcome::succeeded("No s'ha pogut activar el mode manteniment; es restaura igualment: {$reason}", $reason, ['was_active' => false, 'enabled' => false]);
        }

        return StepOutcome::succeeded($wasActive ? 'El mode manteniment ja era actiu.' : 'Mode manteniment activat.', '', ['was_active' => $wasActive, 'enabled' => ! $wasActive]);
    }

    /**
     * Puts the mode back as it was before the run: on if it was, off if this
     * run switched it on. Best effort; returns a warning when it fails.
     */
    public function restoreState(SiteDriver $driver, StepContext $context, bool $on): ?string
    {
        $state = $context->resultOf(self::NAME);
        $wasActive = ($state['was_active'] ?? false) === true;
        $enabled = ($state['enabled'] ?? false) === true;
        if (! $wasActive && ! $enabled) {
            return null;
        }

        return self::switchTo($driver, $on || $wasActive, $this->redactor, $context->run->id);
    }

    /**
     * Switches the mode on or off. Best effort (also for the files rollback,
     * UZEROWPUP-57); returns a warning when it fails.
     */
    public static function switchTo(SiteDriver $driver, bool $on, SecretRedactor $redactor, int $runId): ?string
    {
        try {
            $driver->setMaintenanceMode($on);

            return null;
        } catch (DriverException $e) {
            $reason = $redactor->redact($e->getMessage());
            Log::warning('Could not set the maintenance mode during a restore.', ['run_id' => $runId, 'reason' => $reason]);

            return ($on ? "No s'ha pogut tornar a activar" : "No s'ha pogut desactivar")." el mode manteniment: {$reason}. Revisa'l a mà.";
        }
    }

    /**
     * Whether the mode is on; null when it cannot be told. `is-active` exits 1
     * without an error when it is off; any other failure is unknown.
     */
    public static function state(SiteDriver $driver): ?bool
    {
        try {
            $result = $driver->wp(['maintenance-mode', 'is-active', ...SiteDriver::SAFE_MODE]);
        } catch (DriverException) {
            return null;
        }

        return match (true) {
            $result->successful() => true,
            $result->exitCode === 1 && ! str_contains($result->stderr, 'Error:') => false,
            default => null,
        };
    }

    /**
     * @throws DriverException
     */
    private static function isActive(SiteDriver $driver): bool
    {
        return $driver->wp(['maintenance-mode', 'is-active', ...SiteDriver::SAFE_MODE])->successful();
    }
}
