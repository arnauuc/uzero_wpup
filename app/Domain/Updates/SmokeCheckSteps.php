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

/**
 * Builds the two steps of the minimal layer-3 check (UZEROWPUP-56) and fixes
 * where they go in an update run:
 *
 *   baseline()  →  UpdateSteps::for($plan)  →  PostUpdateStep (UZEROWPUP-55)  →  check()  →  full verifier (F4)
 *
 * The baseline goes before the first update and the check after everything
 * that changes the site, maintenance mode included (a site still in
 * maintenance answers 503). The run pipeline (UZEROWPUP-60) composes them.
 */
final readonly class SmokeCheckSteps
{
    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     */
    public function __construct(
        private Closure $driverFor,
        private RunAuthorization $authorization,
        private HttpFactory $http,
        private SecretRedactor $redactor,
        private SmokeCheckSettings $settings,
    ) {}

    public function baseline(): PhpLogBaselineStep
    {
        return new PhpLogBaselineStep($this->driverFor, $this->authorization, $this->redactor, $this->settings->stepTimeoutSeconds);
    }

    public function check(): SmokeCheckStep
    {
        return new SmokeCheckStep($this->driverFor, $this->authorization, $this->http, $this->redactor, new PhpLogScanner($this->redactor, $this->settings), $this->settings);
    }
}
