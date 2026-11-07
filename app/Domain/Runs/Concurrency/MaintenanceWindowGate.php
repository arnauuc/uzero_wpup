<?php

declare(strict_types=1);

namespace App\Domain\Runs\Concurrency;

use App\Domain\Sites\MaintenanceWindow;
use App\Models\Run;
use App\Models\Site;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * When a run may start on its site (ADR-0006 §1 and §3): an update starts only
 * inside the site's maintenance window and with at least `minimumMinutes` of
 * it left, so it never starts a site that would end outside its window. It
 * only gates the START: an interrupted run is resumed or rolled back whatever
 * the time (never left half done), and a window does not cut a running one.
 *
 * Manual runs share the rule; an admin's `--now` (audited by the command)
 * skips it, as it skips the global queue. Until the scheduler (UZEROWPUP-129)
 * estimates each site's duration from its history, the minimum is one fixed
 * value (wpup.updates.window.minimum_minutes).
 */
final readonly class MaintenanceWindowGate
{
    /**
     * @param  array<mixed>  $defaultWindow  wpup.sites.default_window
     */
    public function __construct(
        private array $defaultWindow,
        private string $timezone,
        private int $minimumMinutes,
        private bool $enforced = true,
    ) {}

    /**
     * @throws InvalidArgumentException when the site's stored window is malformed, or
     *                                  shorter than the minimum (no update could ever start in it)
     */
    public function windowOf(Site $site): MaintenanceWindow
    {
        $window = MaintenanceWindow::of($site, $this->defaultWindow, $this->timezone);
        if ($window->lengthMinutes() < $this->minimumMinutes) {
            throw new InvalidArgumentException("La finestra {$window->label()} dura menys dels {$this->minimumMinutes} minuts mínims per començar una actualització: no en començaria mai cap. Allarga-la amb `wpup site:edit`.");
        }

        return $window;
    }

    /**
     * Null when the site may start now (always, when the windows are not
     * enforced); otherwise the next moment it may.
     *
     * @throws InvalidArgumentException when the site's stored window is malformed
     */
    public function nextStart(Site $site, CarbonImmutable $now): ?CarbonImmutable
    {
        if (! $this->enforced) {
            return null;
        }
        $window = $this->windowOf($site);
        $closes = $window->closesAt($now);
        if ($closes !== null && $now->diffInSeconds($closes, true) >= $this->minimumMinutes * 60) {
            return null;
        }

        // Too little left of the open window counts as closed: the next opening is a later one.
        return $window->nextOpening($now);
    }

    /**
     * @throws OutsideMaintenanceWindow when the run must wait
     * @throws InvalidArgumentException when the site's stored window is malformed
     */
    public function assertMayStart(Run $run, CarbonImmutable $now): void
    {
        $site = $run->site;
        if (! $run->type->respectsMaintenanceWindow() || $site === null || ($run->flags['now'] ?? false) === true) {
            return;
        }

        $opens = $this->nextStart($site, $now);
        if ($opens !== null) {
            throw new OutsideMaintenanceWindow($opens, $this->windowOf($site)->label());
        }
    }
}
