<?php

declare(strict_types=1);

namespace App\Domain\Rollback;

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\FilesBackup;
use App\Domain\Backups\IntactCopy;
use App\Domain\Backups\RestoreRefused;
use App\Domain\Backups\RestoreSelection;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Backups\SiteRestore;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Identity\Ability;
use App\Domain\Runs\ReadOnlyStep;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\BackupKind;
use App\Models\Backup;
use App\Models\Site;
use Closure;

/**
 * First check of a manual rollback (`wpup rollback <run>`, UZEROWPUP-61),
 * before anything is written, so it also runs under --dry-run: the run is a
 * finished update of the site with something to undo and no later update
 * still applied (RollbackSource, ManualRollback), and its restore point has,
 * of the files and of the database, a live, verified and unexpired copy
 * whose destination still holds the registered SHA-256 (SiteRestore::verify,
 * computed from the bytes there; an external copy is hashed once, while the
 * rollback steps download it, before writing anything). Otherwise the
 * rollback is refused and the site is not touched. The rollback steps use
 * exactly the copies it chose (checkedCopy()).
 *
 * Its result says what the archive left out (`pre_restore_excludes`), so the
 * point of the current state (PreRestoreBackupStep) covers everything the
 * files rollback may overwrite or delete, as RestoreCheckStep does.
 */
final readonly class RollbackCheckStep implements ReadOnlyStep
{
    public const string NAME = 'rollback-check';

    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     */
    public function __construct(
        private ManualRollback $rollback,
        private SiteRestore $restore,
        private FilesBackup $files,
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
        return "Comprovarà que l'actualització es pot desfer i el SHA-256 de les còpies del seu punt de restauració a la destinació.";
    }

    public function run(StepContext $context): StepOutcome
    {
        $run = $context->run;
        if ($run->user_id === null) {
            return StepOutcome::failed("Un rollback manual l'ha de llançar un operador: no s'ha executat.");
        }
        $actor = $this->authorization->actorFor($run, Ability::RunOperations);
        if ($actor === null) {
            return StepOutcome::failed(RunAuthorization::deniedMessage($run));
        }

        $warnings = [];

        try {
            $source = RollbackSource::of($run);
            $site = $source->site ?? throw new RollbackRefused("L'execució #{$source->id} no té cap lloc.");
            $this->rollback->assertRevertible($source, $run);
            $driver = null;
            $chosen = [];
            $excludes = null;
            foreach ($this->rollback->copies($site, $source) as $candidates) {
                [$row, $target] = $this->firstIntact($site, $actor, $candidates, $warnings);
                $chosen[$row->kind->value] = $row;
                if ($row->kind === BackupKind::Files) {
                    $driver ??= ($this->driverFor)($site, $actor);
                    $excludes = $this->restore->archiveExcludes($target, $driver, $row) ?? $this->files->excludes(true);
                }
            }
        } catch (RollbackRefused $e) {
            return StepOutcome::failed($e->getMessage().' No s\'ha tocat el lloc.');
        } catch (DriverException $e) {
            return StepOutcome::failed("No s'ha pogut comprovar el lloc: {$this->redactor->redact($e->getMessage())} No s'ha tocat el lloc.");
        }

        $summary = "Actualització #{$source->id} reversible; còpies triades: ".implode(', ', array_map(
            static fn (Backup $row): string => "#{$row->id} ".($row->kind === BackupKind::Database ? 'BD' : 'fitxers').' ('.($row->backupTarget->name ?? '?').')',
            $chosen,
        ));

        return StepOutcome::succeeded($summary.($warnings === [] ? '' : sprintf('; %d avisos', count($warnings))), implode("\n", $warnings), [
            'source_run_id' => $source->id,
            'chosen' => array_map(static fn (Backup $row): int => $row->id, $chosen),
            'pre_restore_excludes' => $excludes,
        ]);
    }

    /**
     * Of $candidates (a step's own choice of copies of the point), the one
     * this run's check verified, so the rollback restores exactly that copy:
     * never another one, nor one that expired since. Unchanged when the run
     * has no check step (the update's own rollback, UZEROWPUP-60).
     *
     * @param  non-empty-list<Backup>  $candidates
     * @return non-empty-list<Backup>
     *
     * @throws RollbackRefused when the verified copy is not among them any more (nothing restored)
     */
    public static function checkedCopy(StepContext $context, BackupKind $kind, array $candidates): array
    {
        $result = $context->resultOf(self::NAME);
        if ($result === []) {
            return $candidates;
        }
        $id = is_array($result['chosen'] ?? null) ? ($result['chosen'][$kind->value] ?? null) : null;
        $checked = array_values(array_filter($candidates, static fn (Backup $row): bool => $row->id === $id));
        if ($checked === []) {
            throw new RollbackRefused('La còpia '.RestoreSelection::kindLabel($kind).(is_int($id) ? " #{$id}" : '').' que ha comprovat aquest rollback ja no es pot fer servir.');
        }

        return $checked;
    }

    /**
     * The first copy that can be used. One at the site-local store is hashed
     * on the site now (cheap, nothing transferred). An external one is not
     * downloaded here: the files and database steps hash it while they
     * download it, before anything is written to the site, so it is
     * transferred and verified once.
     *
     * @param  non-empty-list<Backup>  $candidates
     * @param  list<string>  $warnings
     * @return array{Backup, BackupTarget}
     *
     * @throws RollbackRefused when no copy holds its registered bytes
     */
    private function firstIntact(Site $site, AuditActor $actor, array $candidates, array &$warnings): array
    {
        try {
            return IntactCopy::first($candidates, function (Backup $row) use ($site, $actor, &$warnings): BackupTarget {
                $target = $this->restore->target($site, $row, $actor);
                if (! $target instanceof SiteLocalTarget) {
                    $warnings[] = "La còpia #{$row->id} és a «".($row->backupTarget->name ?? '?')."»: se'n comprovarà el SHA-256 mentre es descarregui, abans d'escriure res al lloc.";

                    return $target;
                }

                return $this->restore->verify($site, $row, $actor);
            }, $warnings);
        } catch (RestoreRefused $e) {
            throw new RollbackRefused($e->getMessage().' Cap còpia '.RestoreSelection::kindLabel($candidates[0]->kind)
                .' no supera la comprovació d\'integritat: no es fa el rollback.');
        }
    }
}
