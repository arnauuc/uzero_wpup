<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Secrets\SecretRedactor;
use Illuminate\Support\Facades\Log;

/**
 * Reads and switches the maintenance mode of a site, for everything that
 * needs it (restore, rollback and the update flow, UZEROWPUP-53).
 */
final class MaintenanceMode
{
    /**
     * Whether the mode is on; null when it cannot be told. `is-active` exits 1
     * without an error when it is off; any other failure is unknown. Note that
     * WP-CLI reports a `.maintenance` file older than 10 minutes as inactive.
     *
     * @param  int|null  $timeoutSeconds  the WP-CLI call's timeout (null: the driver's own)
     */
    public static function state(SiteDriver $driver, ?int $timeoutSeconds = null): ?bool
    {
        try {
            $result = $driver->wp(['maintenance-mode', 'is-active', ...SiteDriver::SAFE_MODE], $timeoutSeconds);
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
     * Switches the mode on or off, within the driver's own time budget. Best
     * effort; returns a warning when it fails.
     */
    public static function switchTo(SiteDriver $driver, bool $on, SecretRedactor $redactor, int $runId): ?string
    {
        try {
            $driver->setMaintenanceMode($on);

            return null;
        } catch (DriverException $e) {
            $reason = $redactor->redact($e->getMessage());
            Log::warning('Could not set the maintenance mode.', ['run_id' => $runId, 'on' => $on, 'reason' => $reason]);

            return ($on ? "No s'ha pogut activar" : "No s'ha pogut desactivar")." el mode manteniment: {$reason}. Revisa'l a mà.";
        }
    }
}
