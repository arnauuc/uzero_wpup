<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\RestoreMaintenanceStep;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Identity\Ability;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Secrets\SecretRedactor;
use App\Models\Run;
use App\Models\Site;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * What the maintenance steps (UZEROWPUP-53) share: whether the site's profile
 * asks for maintenance mode, the state the site was in before the run (kept in
 * `runs.flags.maintenance_initial` BEFORE anything is switched, so a worker
 * that dies right after switching it on does not make a resumed step believe
 * the site was in maintenance already), the driver and the refresh of the
 * `.maintenance` file.
 */
final readonly class MaintenanceGuard
{
    public const string FLAG = 'maintenance_initial';

    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     */
    public function __construct(
        private Closure $driverFor,
        private RunAuthorization $authorization,
        private SecretRedactor $redactor,
        private bool $defaultOn,
        private int $commandTimeoutSeconds,
    ) {}

    /**
     * The profile's choice, or the configured default.
     */
    public function enabledFor(Site $site): bool
    {
        return $site->profile->maintenance_mode ?? $this->defaultOn;
    }

    /**
     * The state before the run: true = it was in maintenance, null = not yet known.
     */
    public function initial(Run $run): ?bool
    {
        $value = $run->flags[self::FLAG] ?? null;

        return is_bool($value) ? $value : null;
    }

    public function remember(Run $run, bool $wasActive): void
    {
        $run->update(['flags' => [...$run->flags, self::FLAG => $wasActive]]);
    }

    /**
     * @return SiteDriver|string the driver, or why there is none (a step failure message)
     */
    public function driver(Run $run): SiteDriver|string
    {
        $site = $run->site;
        if ($site === null) {
            return "L'execució no té cap lloc.";
        }
        $actor = $this->authorization->actorFor($run, Ability::RunOperations);
        if ($actor === null) {
            return RunAuthorization::deniedMessage($run);
        }

        try {
            return ($this->driverFor)($site, $actor);
        } catch (DriverException $e) {
            return "No s'ha pogut connectar amb el lloc: {$this->redactor->redact($e->getMessage())}";
        }
    }

    /**
     * Switches the mode; returns the reason when it fails.
     */
    public function switchTo(SiteDriver $driver, bool $on, int $runId): ?string
    {
        return RestoreMaintenanceStep::switchTo($driver, $on, $this->redactor, $runId);
    }

    /**
     * Writes the `.maintenance` file again: WordPress ignores one older than
     * 10 minutes (wpup.state.maintenance_ttl_seconds), so long operations call
     * this before they start. Only when this run manages the mode and it is on;
     * best effort (a warning in the log, never a failure).
     */
    public function refresh(SiteDriver $driver, Run $run): void
    {
        if ($this->initial($run) === null || RestoreMaintenanceStep::state($driver) !== true) {
            return;
        }

        try {
            $driver->wp(['maintenance-mode', 'activate', '--force', ...SiteDriver::SAFE_MODE], $this->commandTimeoutSeconds);
        } catch (DriverException $e) {
            Log::warning('Could not refresh the maintenance file.', ['run_id' => $run->id, 'reason' => $this->redactor->redact($e->getMessage())]);
        }
    }
}
