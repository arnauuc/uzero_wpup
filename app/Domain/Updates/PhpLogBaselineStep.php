<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Identity\Ability;
use App\Domain\Runs\ReadOnlyStep;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretRedactor;
use App\Models\Site;
use Closure;

/**
 * Saves the position of the PHP error log before anything is updated
 * (UZEROWPUP-56), so SmokeCheckStep compares only the lines written after it.
 * Reads only, so it also runs under --dry-run. The position goes in the
 * step's data (kept by the engine and given to later steps); running it again
 * just reads the current end again, which is right as long as no update has
 * started (it always runs before them).
 *
 * An unreadable log is not an error here: the baseline is stored as "no
 * log" and the smoke check will warn and keep its HTTP check.
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
        return "Desarà la posició actual del log d'errors de PHP, per mirar només les línies noves després d'actualitzar.";
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

        $none = ['path' => null, 'offset' => 0, 'file_id' => null];
        try {
            $cursor = ($this->driverFor)($site, $actor)->phpLogCursor();
        } catch (DriverException $e) {
            return StepOutcome::succeeded(
                "AVÍS: no s'ha pogut llegir el log de PHP ({$this->redactor->redact($e->getMessage())}); després només es comprovarà la resposta HTTP.",
                '',
                $none,
            );
        }

        if ($cursor->path === null) {
            return StepOutcome::succeeded("AVÍS: el lloc no té cap log d'errors de PHP localitzable; després només es comprovarà la resposta HTTP.", '', $none);
        }

        return StepOutcome::succeeded(
            "Log de PHP «{$cursor->path}» desat a la posició {$cursor->offset}.",
            '',
            ['path' => $cursor->path, 'offset' => $cursor->offset, 'file_id' => $cursor->fileId],
        );
    }
}
