<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Data\LogCursor;
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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Minimal compatibility layer 3 (UZEROWPUP-56), after the updates and before
 * the full verifier: any new PHP fatal in the error log since the baseline,
 * or a 5xx (or no answer at all) from the site's home, fails the step, and
 * the failure makes the engine roll back (wired by UZEROWPUP-60).
 *
 * Conservative on purpose:
 * - Log unreadable, or no log known at the baseline: warning (AVÍS in the
 *   summary), the HTTP check still decides.
 * - Log rotated: the driver reads the new file from its start (capped).
 * - HTTP connection error or timeout: failure. A site that does not answer
 *   after an update may be down, and a false rollback is cheaper than a
 *   store left down.
 * - 4xx and redirects (followed up to a limit) are not failures: only the
 *   server breaking is. The home is requested without credentials or cookies.
 *
 * Read only and repeatable: running it again just checks again. Output shows
 * only redacted, truncated lines.
 */
final readonly class SmokeCheckStep implements Step
{
    public const string NAME = 'smoke:check';

    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     */
    public function __construct(
        private Closure $driverFor,
        private RunAuthorization $authorization,
        private HttpFactory $http,
        private SecretRedactor $redactor,
        private PhpLogScanner $scanner,
        private SmokeCheckSettings $settings,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function timeoutSeconds(): int
    {
        return $this->settings->stepTimeoutSeconds;
    }

    public function describe(StepContext $context): string
    {
        return "Llegirà les línies noves del log d'errors de PHP i demanarà la portada del lloc; un fatal nou o una resposta 5xx farà revertir l'actualització.";
    }

    public function run(StepContext $context): StepOutcome
    {
        $actor = $this->authorization->actorFor($context->run, Ability::RunOperations);
        if ($actor === null) {
            return StepOutcome::failed(RunAuthorization::deniedMessage($context->run));
        }
        $site = $context->run->site;
        if ($site === null) {
            return StepOutcome::failed("L'execució no té cap lloc.");
        }

        $warnings = [];
        $fatals = ['count' => 0, 'lines' => []];
        $baseline = $context->resultOf(PhpLogBaselineStep::NAME);
        $path = $baseline['path'] ?? null;
        if ($baseline === []) {
            $warnings[] = 'no hi ha cap posició base del log de PHP (el pas «'.PhpLogBaselineStep::NAME."» no s'ha executat)";
        } elseif (! is_string($path)) {
            $warnings[] = "el log de PHP no era llegible abans d'actualitzar";
        } else {
            try {
                $driver = ($this->driverFor)($site, $actor);
                $cursor = new LogCursor($path, is_int($baseline['offset'] ?? null) ? $baseline['offset'] : 0, is_string($baseline['file_id'] ?? null) ? $baseline['file_id'] : null);
                $fatals = $this->scanner->fatals($driver->phpLogSince($cursor, $this->settings->logMaxBytes));
            } catch (DriverException $e) {
                $warnings[] = "no s'ha pogut llegir el log de PHP: ".$this->redactor->redact($e->getMessage());
            }
        }

        $http = $this->requestHome($site);
        $problems = [];
        if ($fatals['count'] > 0) {
            $problems[] = "{$fatals['count']} error(s) fatal(s) nou(s) de PHP";
        }
        if ($http['failure'] !== null) {
            $problems[] = $http['failure'];
        }

        $output = implode("\n", [...array_map(static fn (string $w): string => "AVÍS: {$w}", $warnings), ...$fatals['lines']]);
        $data = ['fatals' => $fatals['count'], 'http_status' => $http['status'], 'warnings' => $warnings];
        $warned = $warnings === [] ? '' : ' AVÍS: '.implode('; ', $warnings).'.';

        if ($problems !== []) {
            return new StepOutcome(RunStepStatus::Failed, 'La comprovació mínima ha fallat: '.implode('; ', $problems).'.'.$warned, $output, $data);
        }

        return StepOutcome::succeeded("Cap fatal nou al log de PHP i la portada respon HTTP {$http['status']}.".$warned, $output, $data);
    }

    /**
     * @return array{status: ?int, failure: ?string}
     */
    private function requestHome(Site $site): array
    {
        try {
            $response = $this->http
                ->timeout($this->settings->httpTimeoutSeconds)
                ->withOptions([
                    'allow_redirects' => ['max' => $this->settings->maxRedirects, 'protocols' => ['http', 'https']],
                    'verify' => $this->settings->verifyTls,
                    'cookies' => false,
                    'stream' => true,
                ])
                ->withHeaders(['Accept' => 'text/html'])
                ->get($site->url);
        } catch (ConnectionException $e) {
            return ['status' => null, 'failure' => 'la portada no respon ('.$this->redactor->redact($e->getMessage()).')'];
        }

        $status = $response->status();

        return ['status' => $status, 'failure' => $status >= 500 ? "la portada respon HTTP {$status}" : null];
    }
}
