<?php

declare(strict_types=1);

namespace App\Domain\Rollback;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Backups\PostRestoreCheckFailed;
use App\Domain\Backups\RestoreRefused;
use App\Domain\Backups\RestoreSelection;
use App\Domain\Backups\SiteRestore;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Identity\Ability;
use App\Domain\Runs\Exceptions\RunException;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\AuditResult;
use App\Enums\BackupKind;
use App\Models\Backup;
use App\Models\Run;
use App\Models\Site;
use App\Models\SiteSnapshot;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Puts back the code of an update run's own restore point (UZEROWPUP-57). A
 * files rollback is always safe (spec, "Rollback i comandes noves"): the
 * database is never touched here (UZEROWPUP-59).
 *
 * - The code archive of the run's point: the site-local copy first, then the
 *   external ones, the first whose destination still holds its registered
 *   SHA-256 (SiteRestore::verify); the driver checks it again on the site
 *   before using it.
 * - Exact within the archive's scope: SiteDriver::restoreFiles() mirrors it
 *   with deletion, so components the update removed come back and those it
 *   added go, and nothing the archive excluded (uploads unless the profile
 *   included them, caches, the work directory) is touched.
 * - Afterwards the versions on the site must be those from before the update
 *   (RestoredState); any difference fails the step loudly. Only then are the
 *   applied changes marked reverted (RevertedChanges).
 * - Idempotent: a retried step mirrors the same archive again and checks
 *   again. The maintenance mode is left as it was (the mirror removes
 *   `.maintenance`), so a shop does not take orders between this step and
 *   the database rollback.
 *
 * Every attempt is audited (`rollback.files`).
 */
final readonly class FilesRollbackStep implements Step
{
    public const string NAME = 'rollback-files';

    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     */
    public function __construct(
        private RestoreSelection $selection,
        private SiteRestore $restore,
        private RevertedChanges $changes,
        private Closure $driverFor,
        private RunAuthorization $authorization,
        private AuditLogger $audit,
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
        try {
            $source = RollbackSource::of($context->run);
        } catch (RollbackRefused $e) {
            return $e->getMessage();
        }

        return "Restaurarà els fitxers de codi del lloc {$this->siteName($source)} des del punt de restauració de l'execució #{$source->id} "
            .'(la còpia del mateix servidor si és vàlida), en comprovarà les versions i marcarà els canvis com a revertits. La base de dades no es toca.';
    }

    public function run(StepContext $context): StepOutcome
    {
        // Connecting reveals the site's credential: checked again as whoever launched the run (ADR-0011).
        $actor = $this->authorization->actorFor($context->run, Ability::RunOperations);
        if ($actor === null) {
            return StepOutcome::failed(RunAuthorization::deniedMessage($context->run));
        }

        try {
            $source = RollbackSource::of($context->run);
        } catch (RollbackRefused $e) {
            return StepOutcome::failed($e->getMessage());
        }
        $site = $source->site;
        if ($site === null) {
            return StepOutcome::failed("L'execució #{$source->id} no té cap lloc: no hi ha res per restaurar.");
        }
        $audit = ['run_id' => $context->run->id, 'source_run_id' => $source->id, 'backup_id' => null, 'restored' => false];

        try {
            $driver = ($this->driverFor)($site, $actor);
        } catch (DriverException $e) {
            return StepOutcome::failed("No s'ha pogut connectar amb el lloc: no s'ha restaurat res. {$this->redactor->redact($e->getMessage())}");
        }

        $warnings = [];

        try {
            [$row, $warnings] = $this->archive($site, $source, $actor);
            $audit['backup_id'] = $row->id;
            $wasMaintenance = $this->maintenanceActive($driver, $warnings);

            $this->restore->purgeTemporaries($driver);
            $this->restore->restore($site, $driver, $row, $actor);
            $audit['restored'] = true;
            if ($wasMaintenance) {
                $this->keepMaintenance($driver, $warnings);
            }

            try {
                $installed = $driver->installedComponents();
            } catch (DriverException $e) {
                throw PostRestoreCheckFailed::because($e->getMessage());
            }
            $differences = RestoredState::differences(
                $installed,
                SiteSnapshot::query()->where('run_id', $source->id)->first(),
                $source->changes()->where('step', RevertedChanges::UPDATE_STEP)->get(),
            );
            if ($differences !== []) {
                throw PostRestoreCheckFailed::because("les versions no són les d'abans de l'actualització: ".implode(' ', $differences));
            }
            $reverted = $this->changes->mark($source);
        } catch (RunException $e) {
            // The run itself was stopped (timeout, claim lost): audited, never swallowed.
            $this->record($actor, $site, AuditResult::Failure, $audit + ['reason' => 'run stopped']);

            throw $e;
        } catch (RollbackRefused|RestoreRefused|DriverException|PostRestoreCheckFailed $e) {
            $reason = $this->redactor->redact($e->getMessage());
            $this->record($actor, $site, AuditResult::Failure, $audit + ['reason' => $reason]);
            $done = $audit['restored'] ? " Els fitxers ja s'han restaurat des de la còpia #{$audit['backup_id']}: revisa el lloc." : " No s'ha restaurat res.";

            return StepOutcome::failed($reason.$done, implode("\n", $warnings));
        } catch (Throwable $e) {
            $this->record($actor, $site, AuditResult::Failure, $audit + ['exception' => $e::class]);

            throw $e;
        }

        $this->record($actor, $site, AuditResult::Success, $audit + ['reverted_changes' => $reverted]);

        return StepOutcome::succeeded(
            "Fitxers restaurats des de la còpia #{$row->id} de l'execució #{$source->id}; versions comprovades."
                .($warnings === [] ? '' : sprintf(' %d avisos.', count($warnings))),
            implode("\n", $warnings),
            ['backup_id' => $row->id, 'source_run_id' => $source->id, 'reverted_changes' => $reverted],
        );
    }

    /**
     * The code archive of the run's point whose destination holds its bytes,
     * the site-local one first, and a warning for each one skipped.
     *
     * @return array{Backup, list<string>}
     *
     * @throws RollbackRefused
     */
    private function archive(Site $site, Run $source, AuditActor $actor): array
    {
        try {
            $request = $this->selection->select($site, (string) $source->id, [], [BackupKind::Files], false);
            $candidates = $this->selection->candidates($site, $request)[BackupKind::Files->value];
        } catch (RestoreRefused $e) {
            throw new RollbackRefused("L'execució #{$source->id} no té cap còpia dels fitxers utilitzable: {$e->getMessage()}");
        }

        $failures = [];
        foreach ($candidates as $row) {
            try {
                $this->restore->verify($site, $row, $actor);
            } catch (RestoreRefused $e) {
                $failures[] = $e->getMessage();

                continue;
            }

            return [$row, $failures === [] ? [] : [implode(' ', $failures)." Es fa servir la còpia #{$row->id} de «".($row->backupTarget->name ?? '?').'».']];
        }

        throw new RollbackRefused(implode(' ', $failures).' No es restaura res.');
    }

    /**
     * @param  list<string>  $warnings
     */
    private function maintenanceActive(SiteDriver $driver, array &$warnings): bool
    {
        try {
            return $driver->wp(['maintenance-mode', 'is-active', '--skip-plugins', '--skip-themes'])->successful();
        } catch (DriverException $e) {
            $warnings[] = "No s'ha pogut saber si el mode manteniment era actiu: {$this->redactor->redact($e->getMessage())}. Revisa'l a mà.";

            return false;
        }
    }

    /**
     * @param  list<string>  $warnings
     */
    private function keepMaintenance(SiteDriver $driver, array &$warnings): void
    {
        try {
            $driver->setMaintenanceMode(true);
        } catch (DriverException $e) {
            $warnings[] = "No s'ha pogut tornar a activar el mode manteniment: {$this->redactor->redact($e->getMessage())}. Revisa'l a mà.";
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(AuditActor $actor, Site $site, AuditResult $result, array $context): void
    {
        try {
            $this->audit->record($actor, 'rollback.files', $result, 'site', $site->id, $context);
        } catch (Throwable $e) {
            if ($result === AuditResult::Success) {
                throw $e;
            }
            // The failure itself is what the operator must see.
            Log::error('Could not audit a failed files rollback.', ['site_id' => $site->id, 'reason' => $this->redactor->redact($e->getMessage())]);
        }
    }

    private function siteName(Run $source): string
    {
        return $source->site->name ?? '?';
    }
}
