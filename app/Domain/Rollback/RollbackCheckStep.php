<?php

declare(strict_types=1);

namespace App\Domain\Rollback;

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\FilesBackup;
use App\Domain\Backups\RestoreRefused;
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
 * computed from the bytes there). Otherwise the rollback is refused and the
 * site is not touched.
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

        $summary = "Actualització #{$source->id} reversible; còpies íntegres a la destinació: ".implode(', ', array_map(
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
     * @param  non-empty-list<Backup>  $candidates
     * @param  list<string>  $warnings
     * @return array{Backup, BackupTarget}
     *
     * @throws RollbackRefused when no copy holds its registered bytes
     */
    private function firstIntact(Site $site, AuditActor $actor, array $candidates, array &$warnings): array
    {
        $failures = [];
        foreach ($candidates as $row) {
            try {
                $target = $this->restore->verify($site, $row, $actor);
            } catch (RestoreRefused $e) {
                $failures[] = $e->getMessage();

                continue;
            }
            if ($failures !== []) {
                $warnings[] = implode(' ', $failures)." Es fa servir la còpia #{$row->id} de «".($row->backupTarget->name ?? '?').'».';
            }

            return [$row, $target];
        }

        throw new RollbackRefused(implode(' ', $failures).' Cap còpia '.($candidates[0]->kind === BackupKind::Database ? 'de la base de dades' : 'dels fitxers')
            .' no supera la comprovació d\'integritat: no es fa el rollback.');
    }
}
