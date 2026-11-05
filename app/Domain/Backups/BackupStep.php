<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Identity\Ability;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Enums\BackupKind;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Site;
use Closure;

/**
 * Makes the run's restore point (RestorePointService). It writes on the site,
 * so a dry run only describes it. Retrying it after a crash reuses a complete
 * point or redoes an incomplete one.
 */
final readonly class BackupStep implements Step
{
    public const string NAME = 'backup';

    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor  resolves the driver of the run's site
     *                                                            (DriverFactory::forSite); credential
     *                                                            reveals are audited as $actor
     */
    public function __construct(
        private RestorePointService $restorePoints,
        private Closure $driverFor,
        private RunAuthorization $authorization,
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
        $site = $context->run->site;
        if ($site === null) {
            return "No farà cap punt de restauració: l'execució no té cap lloc.";
        }
        $uploads = ($site->profile->include_uploads ?? false) ? 'amb uploads' : 'sense uploads';
        $external = $site->externalBackupTargets()->get()->map(static fn (BackupTargetRecord $t): string => $t->name)->all();

        return "Farà un punt de restauració del lloc {$site->name}: volcat complet de la base de dades i arxiu del codi ({$uploads}), desats al servidor del lloc amb el SHA-256 comprovat"
            .($external === [] ? '.' : ', i en copiarà els fitxers a '.implode(', ', $external).'.');
    }

    public function run(StepContext $context): StepOutcome
    {
        // Connecting reveals the site's credential: checked again as whoever launched the run (ADR-0011).
        $actor = $this->authorization->actorFor($context->run, Ability::RunOperations);
        if ($actor === null) {
            return StepOutcome::failed(RunAuthorization::deniedMessage($context->run));
        }

        $site = $context->run->site;
        if ($site === null) {
            return StepOutcome::failed("L'execució no té cap lloc: no hi ha res per copiar.");
        }

        try {
            $point = $this->restorePoints->create($site, ($this->driverFor)($site, $actor), $context->run, $actor);
        } catch (BackupFailed|DriverException $e) {
            return StepOutcome::failed($e->getMessage());
        }

        $summary = sprintf(
            'Punt de restauració verificat%s: BD %s, codi %s',
            $point->reused ? ' (ja fet)' : '',
            ByteSize::human($point->local(BackupKind::Database)->size_bytes ?? 0),
            ByteSize::human($point->local(BackupKind::Files)->size_bytes ?? 0),
        ).($point->warnings === [] ? '' : sprintf('; %d avisos', count($point->warnings)));

        return StepOutcome::succeeded($summary, implode("\n", $point->warnings), $point->summary());
    }
}
