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
use App\Domain\Drivers\Exceptions\IntegrityCheckFailed;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Identity\Ability;
use App\Domain\Maintenance\MaintenanceMode;
use App\Domain\Runs\Exceptions\RunException;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Updates\UpdateChanges;
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
 * - The code archive of the run's point, the site-local copy first, then the
 *   external ones. Each one is checked once, by SiteRestore::restore(): the
 *   driver checks a site-local copy's size and SHA-256 on the site, and an
 *   external one is hashed while it is downloaded. A copy that fails that
 *   check changed nothing, so the next one is tried.
 * - Exact within the archive's scope: SiteDriver::restoreFiles() mirrors it
 *   with deletion, so components the update removed come back and those it
 *   added go, and nothing the archive excluded (uploads unless the profile
 *   included them, caches, the work directory) is touched.
 * - Right before the mirror the update is marked as being undone
 *   (RevertedChanges::started): the retention holds its point until it is
 *   reviewed, whatever the rollback ends as (UZEROWPUP-61).
 * - A failure during the mirror may leave the site half restored: the step
 *   fails saying so, never "nothing restored".
 * - Afterwards the components the update touched must be back at their
 *   versions from before (RestoredState); any difference fails the step
 *   loudly. Only then are the applied changes marked reverted (RevertedChanges).
 * - Idempotent: a retried step mirrors the same archive again and checks
 *   again.
 * - Maintenance mode: the mirror removes `.maintenance`. If it was on, or its
 *   state could not be told, it is switched on again once the mirror was
 *   attempted, whatever happens, so a shop does not take orders before the
 *   database rollback.
 *
 * Every attempt is audited (`rollback.files`; restored: no, unknown or yes).
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
        $audit = ['run_id' => $context->run->id, 'source_run_id' => $source->id, 'backup_id' => null, 'restored' => 'no'];

        try {
            $driver = ($this->driverFor)($site, $actor);
        } catch (DriverException $e) {
            return StepOutcome::failed("No s'ha pogut connectar amb el lloc: no s'ha restaurat res. {$this->redactor->redact($e->getMessage())}");
        }

        $warnings = [];
        $maintenance = null;

        try {
            $candidates = $this->candidates($site, $source);
            $maintenance = MaintenanceMode::state($driver);
            if ($maintenance === null) {
                $warnings[] = "No s'ha pogut saber si el mode manteniment era actiu: s'activarà després de restaurar els fitxers. Revisa'l a mà.";
            }
            $this->restore->purgeTemporaries($driver);
            $this->changes->started($source);
            $row = $this->mirror($site, $driver, $candidates, $actor, $audit, $warnings);

            try {
                $installed = $driver->installedComponents();
            } catch (DriverException $e) {
                throw PostRestoreCheckFailed::because($e->getMessage());
            }
            $differences = RestoredState::differences(
                $installed,
                SiteSnapshot::query()->where('run_id', $source->id)->first(),
                $source->changes()->where('step', UpdateChanges::STEP)->get(),
            );
            if ($differences !== []) {
                throw PostRestoreCheckFailed::because("les versions no són les d'abans de l'actualització: ".implode(' ', $differences));
            }
            $reverted = $this->changes->mark($source);
        } catch (RunException $e) {
            // The run itself was stopped (timeout, claim lost): audited, never swallowed.
            $this->keepMaintenance($driver, $maintenance, $audit, $context->run->id, $warnings);
            $this->record($actor, $site, AuditResult::Failure, $audit + ['reason' => 'run stopped']);

            throw $e;
        } catch (RollbackRefused|RestoreRefused|DriverException|PostRestoreCheckFailed $e) {
            $this->keepMaintenance($driver, $maintenance, $audit, $context->run->id, $warnings);
            $reason = $this->redactor->redact($e->getMessage());
            $this->record($actor, $site, AuditResult::Failure, $audit + ['reason' => $reason]);

            return StepOutcome::failed($reason.$this->done($audit), implode("\n", $warnings));
        } catch (Throwable $e) {
            $this->keepMaintenance($driver, $maintenance, $audit, $context->run->id, $warnings);
            $this->record($actor, $site, AuditResult::Failure, $audit + ['exception' => $e::class]);

            throw $e;
        }
        $this->keepMaintenance($driver, $maintenance, $audit, $context->run->id, $warnings);

        $this->record($actor, $site, AuditResult::Success, $audit + ['reverted_changes' => $reverted]);

        return StepOutcome::succeeded(
            "Fitxers restaurats des de la còpia #{$row->id} de l'execució #{$source->id}; versions comprovades."
                .($warnings === [] ? '' : sprintf(' %d avisos.', count($warnings))),
            implode("\n", $warnings),
            ['backup_id' => $row->id, 'source_run_id' => $source->id, 'reverted_changes' => $reverted],
        );
    }

    /**
     * @return non-empty-list<Backup>
     *
     * @throws RollbackRefused
     */
    private function candidates(Site $site, Run $source): array
    {
        try {
            return $this->selection->ofPoint($site, (string) $source->id, BackupKind::Files);
        } catch (RestoreRefused $e) {
            throw new RollbackRefused("L'execució #{$source->id} no té cap còpia dels fitxers utilitzable: {$e->getMessage()} No s'ha restaurat res.");
        }
    }

    /**
     * Mirrors the first candidate whose bytes are the registered ones. A copy
     * refused by its check changed nothing; any other failure may have.
     *
     * @param  non-empty-list<Backup>  $candidates
     * @param  array<string, mixed>  $audit
     * @param  list<string>  $warnings
     *
     * @throws RollbackRefused when no copy holds its bytes (nothing restored)
     * @throws DriverException when the mirror failed (the site may be half restored)
     */
    private function mirror(Site $site, SiteDriver $driver, array $candidates, AuditActor $actor, array &$audit, array &$warnings): Backup
    {
        $failures = [];
        foreach ($candidates as $row) {
            $audit['backup_id'] = $row->id;
            $audit['restored'] = 'unknown';

            try {
                $this->restore->restore($site, $driver, $row, $actor);
            } catch (RestoreRefused|IntegrityCheckFailed $e) {
                $audit['restored'] = 'no';
                $failures[] = "Còpia #{$row->id}: {$this->redactor->redact($e->getMessage())}";

                continue;
            }
            $audit['restored'] = 'yes';
            if ($failures !== []) {
                $warnings[] = implode(' ', $failures)." Es fa servir la còpia #{$row->id} de «".($row->backupTarget->name ?? '?').'».';
            }

            return $row;
        }

        $audit['backup_id'] = null;

        throw new RollbackRefused(implode(' ', $failures).' Cap còpia dels fitxers no té el SHA-256 registrat.');
    }

    /**
     * Once the mirror was attempted (it removes `.maintenance`), the mode is
     * switched on again if it was on or its state is unknown.
     *
     * @param  array<string, mixed>  $audit
     * @param  list<string>  $warnings
     */
    private function keepMaintenance(SiteDriver $driver, ?bool $maintenance, array $audit, int $runId, array &$warnings): void
    {
        if ($audit['restored'] === 'no' || $maintenance === false) {
            return;
        }
        $warning = MaintenanceMode::switchTo($driver, true, $this->redactor, $runId);
        if ($warning !== null) {
            $warnings[] = $warning;
        }
    }

    /**
     * @param  array<string, mixed>  $audit
     */
    private function done(array $audit): string
    {
        $id = is_int($audit['backup_id']) ? $audit['backup_id'] : '?';

        return match ($audit['restored']) {
            'yes' => " Els fitxers ja s'han restaurat des de la còpia #{$id}: revisa el lloc.",
            'unknown' => " La restauració dels fitxers des de la còpia #{$id} s'ha interromput: el lloc pot haver quedat restaurat a mitges. Torna a executar el rollback o restaura la còpia a mà.",
            default => " No s'ha restaurat res.",
        };
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
