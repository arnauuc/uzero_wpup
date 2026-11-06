<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Data\LogCursor;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Identity\Ability;
use App\Domain\Runs\Exceptions\StepTimedOut;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\RunStepStatus;
use App\Models\Site;
use Closure;
use GuzzleHttp\Exception\TooManyRedirectsException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Minimal compatibility layer 3 (UZEROWPUP-56), after the updates and before
 * the full verifier: any new PHP fatal in the error log since the baseline,
 * or a 5xx (or no answer at all) from the site's home, fails the step, and
 * the failure makes the engine roll back (wired by UZEROWPUP-60).
 *
 * The home is requested FIRST (http_attempts times, a pause apart, each with its
 * own cache buster; any failure fails) and the log read once afterwards, so a
 * fatal that the requests themselves cause is in the region that is read.
 *
 * Conservative on purpose:
 * - Log unreadable (any error reading it), or no log known at the baseline:
 *   warning (AVÍS in the summary), the HTTP check still decides.
 * - Log rotated or truncated: the driver reads the new file from its start and
 *   the rotated sibling from the old offset; the step warns that lines may
 *   be missing. More candidates than the cap: warning too.
 * - A fatal whose signature was already in the log before the baseline is a
 *   warning ("fatal preexistent repetit"), not a failure.
 * - HTTP connection error, timeout or too many redirects: failure. A site
 *   that does not answer after an update may be down, and a false rollback
 *   is cheaper than a store left down.
 * - 4xx and redirects (followed up to a limit) are not failures: only the
 *   server breaking is. The home is requested without credentials or cookies.
 *
 * Page caches: the request carries a random query parameter and no-cache
 * headers, which defeats WordPress page caches and most proxies. A CDN that
 * disregards both and serves a stored copy can still hide a broken origin;
 * only the log can show it then.
 *
 * Read only and repeatable: running it again just checks again. Output shows
 * only redacted, truncated lines.
 */
final readonly class SmokeCheckStep implements Step
{
    public const string NAME = 'smoke:check';

    /** Query parameter that makes every request a distinct URL for page caches. */
    private const string CACHE_BUSTER = 'wpup-smoke';

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
        return "Demanarà la portada del lloc i llegirà les línies noves del log d'errors de PHP; un fatal nou o una resposta 5xx farà revertir l'actualització.";
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

        // First the requests, then the log: what they break must be in what is read.
        $http = $this->requestHomeRepeatedly($site);
        [$fatals, $warnings] = $this->readLog($context, $site, $actor);

        $problems = [];
        if ($fatals['count'] > 0) {
            $problems[] = "{$fatals['count']} error(s) fatal(s) nou(s) de PHP";
        }
        if ($http['failure'] !== null) {
            $problems[] = $http['failure'];
        }

        $output = implode("\n", [...array_map(static fn (string $w): string => "AVÍS: {$w}", $warnings), ...$fatals['lines']]);
        $data = ['fatals' => $fatals['count'], 'repeated_fatals' => $fatals['repeated'], 'http_status' => $http['status'], 'warnings' => $warnings];
        $warned = $warnings === [] ? '' : ' AVÍS: '.implode('; ', $warnings).'.';

        if ($problems !== []) {
            return new StepOutcome(RunStepStatus::Failed, 'La comprovació mínima ha fallat: '.implode('; ', $problems).'.'.$warned, $output, $data);
        }

        return StepOutcome::succeeded("Cap fatal nou al log de PHP i la portada respon HTTP {$http['status']}.".$warned, $output, $data);
    }

    /**
     * The new fatals and the warnings. Any error reading the log is a warning:
     * the HTTP check still decides (only the step timeout is let through).
     *
     * @return array{array{count: int, repeated: int, lines: list<string>}, list<string>}
     */
    private function readLog(StepContext $context, Site $site, AuditActor $actor): array
    {
        $none = ['count' => 0, 'repeated' => 0, 'lines' => []];
        $baseline = $context->resultOf(PhpLogBaselineStep::NAME);
        $path = $baseline['path'] ?? null;
        if ($baseline === []) {
            return [$none, ['no hi ha cap posició base del log de PHP (el pas «'.PhpLogBaselineStep::NAME."» no s'ha executat)"]];
        }
        if (! is_string($path)) {
            return [$none, ["el log de PHP no era llegible abans d'actualitzar"]];
        }

        $warnings = [];
        if (($baseline['web_verified'] ?? false) !== true) {
            $warnings[] = 'camí del log no verificat pel web';
        }
        try {
            $cursor = new LogCursor($path, is_int($baseline['offset'] ?? null) ? $baseline['offset'] : 0, is_string($baseline['file_id'] ?? null) ? $baseline['file_id'] : null);
            $known = array_values(array_filter(is_array($baseline['signatures'] ?? null) ? $baseline['signatures'] : [], is_string(...)));
            $scan = ($this->driverFor)($site, $actor)->phpLogFatalsSince($cursor, $this->settings->maxCandidateLines);
            $fatals = $this->scanner->fatals($scan->lines, $known);
        } catch (StepTimedOut $e) {
            throw $e;
        } catch (Throwable $e) {
            return [$none, [...$warnings, "no s'ha pogut llegir el log de PHP: ".$this->redactor->redact($e->getMessage())]];
        }

        if ($scan->rotated) {
            $warnings[] = "el log ha rotat durant l'actualització: poden faltar línies".($scan->siblingRead ? " (s'ha llegit també el fitxer rotat)" : '');
        }
        if ($scan->capped) {
            $warnings[] = "hi havia més de {$this->settings->maxCandidateLines} línies candidates: només s'han mirat les primeres";
        }
        if ($fatals['repeated'] > 0) {
            $warnings[] = "fatal preexistent repetit ({$fatals['repeated']}): ja era al log abans d'actualitzar";
        }

        return [$fatals, $warnings];
    }

    /**
     * Requests the home http_attempts times, a pause apart and each with its own
     * cache buster, and stops at the first problem: ANY 5xx or connection failure
     * makes the step fail. A worker still serving old code right after an update
     * can answer differently from the next one, so one good answer proves little.
     *
     * @return array{status: ?int, failure: ?string}
     */
    private function requestHomeRepeatedly(Site $site): array
    {
        $result = ['status' => null, 'failure' => null];
        for ($attempt = 1; $attempt <= $this->settings->httpAttempts; $attempt++) {
            if ($attempt > 1 && $this->settings->httpAttemptPauseSeconds > 0) {
                sleep($this->settings->httpAttemptPauseSeconds);
            }
            $result = $this->requestHome($site);
            if ($result['failure'] !== null) {
                return ['status' => $result['status'], 'failure' => "{$result['failure']} (petició {$attempt} de {$this->settings->httpAttempts})"];
            }
        }

        return $result;
    }

    /**
     * @return array{status: ?int, failure: ?string}
     */
    private function requestHome(Site $site): array
    {
        try {
            $response = $this->http
                ->timeout($this->settings->httpTimeoutSeconds)
                ->connectTimeout($this->settings->httpTimeoutSeconds)
                ->withOptions([
                    'allow_redirects' => ['max' => $this->settings->maxRedirects, 'protocols' => ['http', 'https']],
                    'verify' => $this->settings->verifyTls,
                    'cookies' => false,
                    'stream' => true,
                ])
                ->withHeaders(['Accept' => 'text/html', 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache'])
                ->get($this->homeUrl($site));
        } catch (ConnectionException|RequestException|TooManyRedirectsException $e) {
            // Guzzle reports a redirect loop as a connection error whose cause is TooManyRedirectsException.
            if ($e instanceof TooManyRedirectsException || $e->getPrevious() instanceof TooManyRedirectsException || str_contains($e->getMessage(), 'Will not follow more than')) {
                return ['status' => null, 'failure' => "massa redireccions a la portada (més de {$this->settings->maxRedirects})"];
            }

            return ['status' => null, 'failure' => 'la portada no respon ('.$this->redactor->redact($e->getMessage()).')'];
        }

        $status = $response->status();

        return ['status' => $status, 'failure' => $status >= 500 ? "la portada respon HTTP {$status}" : null];
    }

    /** The site's URL plus a random query parameter, so that page caches do not answer. */
    private function homeUrl(Site $site): string
    {
        $url = $site->url;
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            $url = rtrim($url, '/').'/';
        }

        return $url.(str_contains($url, '?') ? '&' : '?').self::CACHE_BUSTER.'='.bin2hex(random_bytes(8));
    }
}
