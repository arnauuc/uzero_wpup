<?php

declare(strict_types=1);

namespace App\Domain\Rollback;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Backups\NewWrites;
use App\Domain\Backups\NewWritesDetector;
use App\Domain\Backups\PostRestoreCheckFailed;
use App\Domain\Backups\RestoreRefused;
use App\Domain\Backups\RestoreSelection;
use App\Domain\Backups\RestoreStep;
use App\Domain\Backups\SiteRestore;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\Exceptions\IntegrityCheckFailed;
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
use App\Enums\RunType;
use App\Models\Backup;
use App\Models\Run;
use App\Models\Site;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Puts back the database of an update run's own restore point, only when it
 * is known that nothing was written to the orders or users since the copy
 * (UZEROWPUP-59, AGENTS.md rule 4, spec "Rollback i comandes noves"). It goes
 * after the files rollback (UZEROWPUP-57), which always happens.
 *
 * - Before touching anything, NewWritesDetector compares the copy's watermark
 *   with the site. Found or unknown (no watermark, one not valid, a site that
 *   cannot be read): the database is not touched. The step succeeds with
 *   "BD no restaurada", and its result (run_steps.result, for the report and
 *   the notification) carries the affected orders and users (ID, type,
 *   status, dates and roles; never personal data) and the command to force it
 *   by hand (`backup:restore --db --accept-data-loss`, typed confirmation).
 * - Nothing written: maintenance mode on (if it was not), and the check runs
 *   again right before the restore, so no order can enter in between. If the
 *   mode cannot be switched on, the database is not restored.
 * - The dump of the run's point, the site-local copy first, then the external
 *   ones; one that fails its checksum changed nothing, so the next is tried.
 *   WP-CLI must answer afterwards and the object cache is flushed.
 * - Maintenance: switched off again only if this step switched it on and the
 *   database was not left half restored. One already on (the files rollback
 *   leaves it on) is left on for whoever owns it.
 * - Idempotent: restored, the site matches the copy's watermark again.
 *
 * Every attempt is audited (`rollback.database`; denied when not restored
 * because of new writes).
 *
 * Result keys: restored (bool), source_run_id, backup_id, new_writes
 * (NewWrites::toArray()), warning (string|null), force_command (string|null).
 */
final readonly class DatabaseRollbackStep implements Step
{
    public const string NAME = 'rollback-database';

    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     */
    public function __construct(
        private RestoreSelection $selection,
        private SiteRestore $restore,
        private NewWritesDetector $newWrites,
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
            $source = self::source($context->run);
        } catch (RollbackRefused $e) {
            return $e->getMessage();
        }

        return 'Restaurarà la base de dades del lloc '.($source->site->name ?? '?')." des del punt de restauració de l'execució #{$source->id} "
            ."només si no hi ha escriptures noves a les comandes ni als usuaris des de la còpia; si n'hi ha, no la tocarà i n'avisarà.";
    }

    public function run(StepContext $context): StepOutcome
    {
        // Connecting reveals the site's credential: checked again as whoever launched the run (ADR-0011).
        $actor = $this->authorization->actorFor($context->run, Ability::RunOperations);
        if ($actor === null) {
            return StepOutcome::failed(RunAuthorization::deniedMessage($context->run));
        }

        try {
            $source = self::source($context->run);
            $site = $source->site ?? throw new RollbackRefused("L'execució #{$source->id} no té cap lloc.");
            $candidates = $this->candidates($site, $source);
        } catch (RollbackRefused $e) {
            return StepOutcome::failed($e->getMessage().' La base de dades no s\'ha restaurat.');
        }
        $audit = ['run_id' => $context->run->id, 'source_run_id' => $source->id, 'backup_id' => null, 'restored' => 'no'];

        try {
            $driver = ($this->driverFor)($site, $actor);
        } catch (DriverException $e) {
            return StepOutcome::failed("No s'ha pogut connectar amb el lloc: la base de dades no s'ha restaurat. {$this->redactor->redact($e->getMessage())}");
        }

        $warnings = [];
        $enabled = false;

        try {
            // First, before touching anything.
            $writes = $this->newWrites->detect($driver, $candidates[0]->order_watermark);
            if ($writes->mayLoseData()) {
                return $this->notRestored($actor, $site, $source, $writes, $audit, []);
            }

            $enabled = $this->maintenanceOn($driver, $warnings);
            $this->restore->purgeTemporaries($driver);
            $row = null;
            $failures = [];
            foreach ($candidates as $candidate) {
                // Again right before the restore, with the shop closed.
                $writes = $this->newWrites->detect($driver, $candidate->order_watermark);
                if ($writes->mayLoseData()) {
                    $this->maintenanceOff($driver, $enabled, $context->run->id, $warnings);

                    return $this->notRestored($actor, $site, $source, $writes, $audit, $warnings);
                }
                $audit['backup_id'] = $candidate->id;
                $audit['restored'] = 'unknown';

                try {
                    $this->restore->restore($site, $driver, $candidate, $actor);
                } catch (RestoreRefused|IntegrityCheckFailed $e) {
                    $audit['restored'] = 'no';
                    $failures[] = "Còpia #{$candidate->id}: {$this->redactor->redact($e->getMessage())}";

                    continue;
                }
                $audit['restored'] = 'yes';
                $row = $candidate;

                break;
            }
            if ($row === null) {
                $audit['backup_id'] = null;

                throw new RollbackRefused(implode(' ', $failures).' Cap còpia de la base de dades no té el SHA-256 registrat.');
            }
            if ($failures !== []) {
                $warnings[] = implode(' ', $failures)." Es fa servir la còpia #{$row->id} de «".($row->backupTarget->name ?? '?').'».';
            }
            $warnings = [...$warnings, ...RestoreStep::checkAfterRestore($driver)];
        } catch (RunException $e) {
            // The run itself was stopped (timeout, claim lost): audited, never swallowed.
            $this->maintenanceOff($driver, $enabled && $audit['restored'] === 'no', $context->run->id, $warnings);
            $this->record($actor, $site, AuditResult::Failure, $audit + ['reason' => 'run stopped']);

            throw $e;
        } catch (RollbackRefused|DriverException|PostRestoreCheckFailed $e) {
            $this->maintenanceOff($driver, $enabled && $audit['restored'] === 'no', $context->run->id, $warnings);
            $reason = $this->redactor->redact($e->getMessage());
            $this->record($actor, $site, AuditResult::Failure, $audit + ['reason' => $reason]);

            return StepOutcome::failed($reason.$this->done($audit, $site, $source), implode("\n", $warnings));
        } catch (Throwable $e) {
            $this->maintenanceOff($driver, $enabled && $audit['restored'] === 'no', $context->run->id, $warnings);
            $this->record($actor, $site, AuditResult::Failure, $audit + ['exception' => $e::class]);

            throw $e;
        }
        $this->maintenanceOff($driver, $enabled, $context->run->id, $warnings);
        $this->record($actor, $site, AuditResult::Success, $audit);

        return StepOutcome::succeeded(
            "Base de dades restaurada des de la còpia #{$row->id} de l'execució #{$source->id}: no hi havia escriptures noves a les comandes ni als usuaris."
                .($warnings === [] ? '' : sprintf(' %d avisos.', count($warnings))),
            implode("\n", $warnings),
            ['restored' => true, 'source_run_id' => $source->id, 'backup_id' => $row->id, 'new_writes' => $writes->toArray(), 'warning' => null, 'force_command' => null],
        );
    }

    /**
     * The update run whose point is restored: the run itself (rollback within
     * the update, UZEROWPUP-60) or the parent of a `rollback` run (UZEROWPUP-61).
     *
     * TODO(UZEROWPUP-59): use RollbackSource::of() once UZEROWPUP-57 is on main.
     *
     * @throws RollbackRefused
     */
    private static function source(Run $run): Run
    {
        $source = $run->type === RunType::Rollback ? $run->parent : $run;
        if ($source === null || $source->type !== RunType::Update) {
            throw new RollbackRefused("L'execució #{$run->id} no indica quina actualització s'ha de desfer.");
        }
        if ($source->site_id === null || $source->site_id !== $run->site_id) {
            throw new RollbackRefused("L'execució #{$source->id} no és del mateix lloc que el rollback.");
        }

        return $source;
    }

    /**
     * @return non-empty-list<Backup>
     *
     * @throws RollbackRefused
     */
    private function candidates(Site $site, Run $source): array
    {
        try {
            $request = $this->selection->select($site, (string) $source->id, [], [BackupKind::Database], false);

            return $this->selection->candidates($site, $request)[BackupKind::Database->value];
        } catch (RestoreRefused $e) {
            throw new RollbackRefused("L'execució #{$source->id} no té cap còpia de la base de dades utilitzable: {$e->getMessage()}");
        }
    }

    /**
     * New writes, or no way to know: the database is not touched.
     *
     * @param  array<string, mixed>  $audit
     * @param  list<string>  $warnings
     */
    private function notRestored(AuditActor $actor, Site $site, Run $source, NewWrites $writes, array $audit, array $warnings): StepOutcome
    {
        $command = "wpup backup:restore {$site->name} --point=run{$source->id} --db --accept-data-loss";
        $warning = "BD no restaurada: {$writes->describe()} Només s'han restaurat els fitxers; la base de dades no s'ha tocat. "
            ."Per restaurar-la igualment, perdent aquestes dades: {$command} (cal confirmar-ho escrivint el nom del lloc).";
        $this->record($actor, $site, AuditResult::Denied, $audit + ['new_writes' => $writes->toArray()]);

        return StepOutcome::succeeded(
            "BD no restaurada: hi ha (o pot haver-hi) escriptures noves a les comandes o als usuaris des de la còpia de l'execució #{$source->id}.",
            implode("\n", [$warning, ...$warnings]),
            ['restored' => false, 'source_run_id' => $source->id, 'backup_id' => null, 'new_writes' => $writes->toArray(), 'warning' => $warning, 'force_command' => $command],
        );
    }

    /**
     * `is-active` exits 1 without an error when the mode is off; any other
     * failure leaves its state unknown: it is switched on and left on.
     *
     * @param  list<string>  $warnings
     * @return bool whether this step switched it on from off
     *
     * @throws RollbackRefused when it cannot be switched on
     */
    private function maintenanceOn(SiteDriver $driver, array &$warnings): bool
    {
        try {
            $state = $driver->wp(['maintenance-mode', 'is-active', '--skip-plugins', '--skip-themes']);
            if ($state->successful()) {
                return false;
            }
            $driver->setMaintenanceMode(true);
            if ($state->exitCode === 1 && trim($state->stderr) === '') {
                return true;
            }
            $warnings[] = "No s'ha pogut saber si el mode manteniment era actiu: es deixa activat. Revisa'l a mà.";

            return false;
        } catch (DriverException $e) {
            throw new RollbackRefused("No s'ha pogut activar el mode manteniment, i sense no es pot garantir que no entri cap comanda abans de restaurar: {$this->redactor->redact($e->getMessage())}");
        }
    }

    /**
     * @param  list<string>  $warnings
     */
    private function maintenanceOff(SiteDriver $driver, bool $switch, int $runId, array &$warnings): void
    {
        if (! $switch) {
            return;
        }

        try {
            $driver->setMaintenanceMode(false);
        } catch (DriverException $e) {
            $reason = $this->redactor->redact($e->getMessage());
            Log::warning('Could not switch the maintenance mode off after a database rollback.', ['run_id' => $runId, 'reason' => $reason]);
            $warnings[] = "No s'ha pogut desactivar el mode manteniment: {$reason}. Revisa'l a mà.";
        }
    }

    /**
     * @param  array<string, mixed>  $audit
     */
    private function done(array $audit, Site $site, Run $source): string
    {
        return match ($audit['restored']) {
            'yes' => ' La base de dades ja s\'ha restaurat: revisa el lloc (el mode manteniment es manté si aquest pas l\'havia activat).',
            'unknown' => ' La restauració de la base de dades s\'ha interromput: pot haver quedat a mitges i el mode manteniment es manté. '
                ."Torna a executar el rollback o restaura-la a mà: wpup backup:restore {$site->name} --point=run{$source->id} --db (més --accept-data-loss en una botiga).",
            default => ' La base de dades no s\'ha restaurat.',
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(AuditActor $actor, Site $site, AuditResult $result, array $context): void
    {
        try {
            $this->audit->record($actor, 'rollback.database', $result, 'site', $site->id, $context);
        } catch (Throwable $e) {
            if ($result === AuditResult::Success) {
                throw $e;
            }
            // The outcome itself is what the operator must see.
            Log::error('Could not audit a database rollback.', ['site_id' => $site->id, 'reason' => $this->redactor->redact($e->getMessage())]);
        }
    }
}
