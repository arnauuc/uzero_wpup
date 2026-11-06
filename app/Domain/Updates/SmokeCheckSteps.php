<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Secrets\SecretRedactor;
use App\Models\Site;
use Closure;
use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;

/**
 * Builds the two steps of the minimal layer-3 check (UZEROWPUP-56) and fixes
 * where they go in an update run:
 *
 *   baseline()  →  UpdateSteps::for($plan)  →  PostUpdateStep (UZEROWPUP-55)  →  check()  →  full verifier (F4)
 *
 * The baseline goes before the first update and the check after everything
 * that changes the site, maintenance mode included (a site still in
 * maintenance answers 503). The run pipeline (UZEROWPUP-60) composes them.
 *
 * The step timeout must cover the worst case (SSH connection, every remote
 * command at its own timeout, the home with all its redirects), or the step
 * could be cut mid-check; it is validated here, as UpdateSteps does.
 */
final readonly class SmokeCheckSteps
{
    private PhpLogScanner $scanner;

    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     *
     * @throws InvalidArgumentException when the step timeout is below the worst case
     */
    public function __construct(
        private Closure $driverFor,
        private RunAuthorization $authorization,
        private HttpFactory $http,
        private SecretRedactor $redactor,
        private SmokeCheckSettings $settings,
        int $sshConnectSeconds,
        int $sshCommandSeconds,
    ) {
        $worst = $settings->worstCaseSeconds($sshConnectSeconds, $sshCommandSeconds);
        if ($settings->stepTimeoutSeconds < $worst) {
            throw new InvalidArgumentException("wpup.smoke_check.step_timeout_seconds ({$settings->stepTimeoutSeconds} s) ha de ser com a mínim el pitjor cas ({$worst} s: connexió SSH + ".SmokeCheckSettings::REMOTE_COMMANDS.' ordres + la portada amb les redireccions).');
        }
        $this->scanner = new PhpLogScanner($redactor, $settings);
    }

    public function baseline(): PhpLogBaselineStep
    {
        return new PhpLogBaselineStep($this->driverFor, $this->authorization, $this->redactor, $this->scanner, $this->settings);
    }

    public function check(): SmokeCheckStep
    {
        return new SmokeCheckStep($this->driverFor, $this->authorization, $this->http, $this->redactor, $this->scanner, $this->settings);
    }
}
