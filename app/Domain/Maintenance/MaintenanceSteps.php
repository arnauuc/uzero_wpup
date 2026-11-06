<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/**
 * The maintenance steps of an update run (UZEROWPUP-53), for the update
 * pipeline (UZEROWPUP-60) to compose in this order:
 *
 *   on → updates → post-update → off → verification → [rollback] → restore
 *
 * - on: before the updates; the mode is on during all of them.
 * - off: right before the smoke check and the verifier, so they see the real
 *   site. If the verification fails, the rollback steps switch the mode on
 *   again (they already do) and restore() leaves it as it was.
 * - restore: always the last step. If the run fails earlier, the compensation
 *   of `on` does the same.
 *
 * The three do nothing when the site's profile asks for no maintenance, and a
 * site that was already in maintenance stays so. Component updates call
 * MaintenanceGuard::refresh() so WordPress does not ignore the `.maintenance`
 * file after 10 minutes.
 */
final readonly class MaintenanceSteps
{
    public function __construct(private MaintenanceGuard $guard, private int $stepTimeoutSeconds) {}

    public function on(): MaintenanceOnStep
    {
        return new MaintenanceOnStep($this->guard, $this->stepTimeoutSeconds);
    }

    public function off(): MaintenanceOffStep
    {
        return new MaintenanceOffStep($this->guard, $this->stepTimeoutSeconds);
    }

    public function restore(): MaintenanceRestoreStep
    {
        return new MaintenanceRestoreStep($this->guard, $this->stepTimeoutSeconds);
    }
}
