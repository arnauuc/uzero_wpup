<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Identity\Ability;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Runs\Exceptions\StepTimedOut;
use App\Domain\Runs\ReadOnlyStep;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretRedactor;
use App\Models\Site;
use App\Models\SiteSnapshot;
use Closure;
use Throwable;

/**
 * Saves the position of the PHP error log before anything is updated
 * (UZEROWPUP-56), so SmokeCheckStep compares only the lines written after it,
 * and the signatures of the fatals already in the last signature_window_bytes
 * before it, so a fatal the site already threw is not blamed on the update.
 *
 * The log is the one the WEB's PHP reported (the HTTP probe's error_log, kept in
 * the inventory snapshot): the CLI's can differ. Without it the CLI's is used,
 * with an explicit warning. Reads only, so it also runs under --dry-run; it is
 * idempotent as long as no update has started (it always runs before them).
 *
 * An unreadable log is not an error here: the baseline is stored as "no log"
 * and the smoke check will warn and keep its HTTP check.
 */
final readonly class PhpLogBaselineStep implements ReadOnlyStep
{
    public const string NAME = 'smoke:php-log-baseline';

    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     */
    public function __construct(
        private Closure $driverFor,
        private RunAuthorization $authorization,
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
        return "Desarà la posició actual del log d'errors de PHP (el del web) i els fatals que ja hi havia, per mirar només els nous després d'actualitzar.";
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

        $none = ['path' => null, 'offset' => 0, 'file_id' => null, 'web_verified' => false, 'signatures' => []];
        $warnings = [];
        try {
            $driver = ($this->driverFor)($site, $actor);
            $webLog = $this->webLogPath($context, $site);
            $cursor = $driver->phpLogCursor($webLog);
            if ($cursor->path === null) {
                return StepOutcome::succeeded("AVÍS: el lloc no té cap log d'errors de PHP localitzable; després només es comprovarà la resposta HTTP.", '', $none);
            }
            $verified = $webLog !== null && $cursor->path === $webLog;
            if (! $verified) {
                $warnings[] = 'camí del log no verificat pel web (es fa servir el que veu la línia de comandes)';
            }
            $signatures = [];
            try {
                $signatures = $this->scanner->signatures($driver->phpLogFatalsBefore($cursor, $this->settings->signatureWindowBytes, $this->settings->maxCandidateLines));
            } catch (StepTimedOut $e) {
                throw $e;
            } catch (Throwable $e) {
                $warnings[] = "no s'han pogut llegir els fatals anteriors: {$this->redactor->redact($e->getMessage())}";
            }
        } catch (StepTimedOut $e) {
            throw $e;
        } catch (Throwable $e) {
            return StepOutcome::succeeded(
                "AVÍS: no s'ha pogut llegir el log de PHP ({$this->redactor->redact($e->getMessage())}); després només es comprovarà la resposta HTTP.",
                '',
                $none,
            );
        }

        $warned = $warnings === [] ? '' : ' AVÍS: '.implode('; ', $warnings).'.';

        return StepOutcome::succeeded(
            "Log de PHP «{$cursor->path}» desat a la posició {$cursor->offset}, amb ".count($signatures).' fatal(s) anterior(s).'.$warned,
            '',
            ['path' => $cursor->path, 'offset' => $cursor->offset, 'file_id' => $cursor->fileId, 'web_verified' => $verified, 'signatures' => $signatures],
        );
    }

    /**
     * The error_log the web's PHP reported to the HTTP probe: from this run's
     * inventory, else the site's latest web-verified snapshot.
     */
    private function webLogPath(StepContext $context, Site $site): ?string
    {
        $id = $context->resultOf(InventoryStep::NAME)['snapshot_id'] ?? null;
        $snapshot = (is_int($id) ? SiteSnapshot::query()->find($id) : null) ?? SiteSnapshot::latestWebVerifiedPhp($site);
        $php = $snapshot?->environment['php'] ?? null;
        $path = is_array($php) ? ($php['error_log'] ?? null) : null;

        return is_string($path) && $path !== '' ? $path : null;
    }
}
