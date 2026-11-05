<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Audit\AuditActor;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Identity\Ability;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Runs\ReadOnlyStep;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Enums\BackupKind;
use App\Models\Backup;
use App\Models\Run;
use App\Models\Site;
use App\Models\SiteSnapshot;
use Closure;

/**
 * First check of a restore run (UZEROWPUP-46), before anything is written,
 * so it also runs under --dry-run:
 *
 * - of each kind, the first candidate copy that is still of the site, live,
 *   verified and whose destination still holds its bytes (a warning says so
 *   when the site-local copy could not be used and an external one is);
 * - what the code archive left out, so that the point of the current state
 *   (PreRestoreBackupStep) covers everything the restore may overwrite or
 *   delete: exactly the archive's own exclude list, read on the site, or, for
 *   an external copy, the configured list with uploads included;
 * - AGENTS.md rule 4 (ShopGuard): the database of a shop, or of a site whose
 *   inventory is unknown, needs --accept-data-loss and the operator's
 *   interactive confirmation. A dry run only warns.
 */
final readonly class RestoreCheckStep implements ReadOnlyStep
{
    public const string NAME = 'restore-check';

    /**
     * @param  Closure(Site, AuditActor): SiteDriver  $driverFor
     */
    public function __construct(
        private RestoreSelection $selection,
        private SiteRestore $restore,
        private FilesBackup $files,
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
        return 'Comprovarà que les còpies triades són del lloc, vives i verificades, i el seu SHA-256 a la destinació.';
    }

    public function run(StepContext $context): StepOutcome
    {
        $actor = self::actor($this->authorization, $context->run);
        $site = $context->run->site;
        if (is_string($actor) || $site === null) {
            return StepOutcome::failed(is_string($actor) ? $actor : "L'execució no té cap lloc.");
        }

        $warnings = [];
        $driver = null;
        $driverFor = function () use (&$driver, $site, $actor): SiteDriver {
            return $driver ??= ($this->driverFor)($site, $actor);
        };

        try {
            $request = RestoreRequest::fromRun($context->run);
            $chosen = [];
            $excludes = null;
            foreach ($this->selection->candidates($site, $request) as $kind => $candidates) {
                [$row, $target] = $this->firstVerified($site, $actor, $candidates, $warnings);
                $chosen[$kind] = $row;
                if ($row->kind === BackupKind::Files) {
                    $excludes = $this->restore->archiveExcludes($target, $driverFor(), $row) ?? $this->files->excludes(true);
                }
            }

            $shop = ShopGuard::isShop($this->snapshot($context, $site));
            if ($request->restoresDatabase() && $shop && ! ($request->acceptDataLoss && $request->dataLossConfirmed)) {
                $why = ShopGuard::message($chosen[BackupKind::Database->value]->created_at->format(BackupListing::dateFormat()))
                    .' Cal --accept-data-loss i confirmar-ho de manera interactiva escrivint el nom del lloc (--force no ho salta).';
                if (! $context->dryRun) {
                    throw new RestoreRefused($why);
                }
                $warnings[] = $why;
            }
        } catch (RestoreRefused $e) {
            return StepOutcome::failed($e->getMessage());
        } catch (DriverException $e) {
            return StepOutcome::failed("No s'ha pogut comprovar el lloc: {$e->getMessage()}");
        }

        $summary = 'Còpies verificades a la destinació: '.implode(', ', array_map(
            static fn (Backup $row): string => "#{$row->id} ".($row->kind === BackupKind::Database ? 'BD' : 'fitxers').' ('.($row->backupTarget->name ?? '?').')',
            $chosen,
        ));

        return StepOutcome::succeeded($summary.($warnings === [] ? '' : sprintf('; %d avisos', count($warnings))), implode("\n", $warnings), [
            'chosen' => array_map(static fn (Backup $row): int => $row->id, $chosen),
            'pre_restore_excludes' => $excludes,
            'shop' => $shop,
            'accepted_data_loss' => $request->restoresDatabase() && $shop && $request->acceptDataLoss && $request->dataLossConfirmed,
            'warnings' => $warnings,
        ]);
    }

    /**
     * Who the restore acts for, or why it must not run: a restore is always
     * an operator's decision, never a scheduler's.
     */
    public static function actor(RunAuthorization $authorization, Run $run): AuditActor|string
    {
        if ($run->user_id === null) {
            return "Una restauració l'ha de llançar un operador: no s'ha executat.";
        }
        if ($run->site === null) {
            return "L'execució no té cap lloc: no hi ha res per restaurar.";
        }

        return $authorization->actorFor($run, Ability::RunOperations) ?? RunAuthorization::deniedMessage($run);
    }

    /**
     * @param  non-empty-list<Backup>  $candidates
     * @param  list<string>  $warnings
     * @return array{Backup, BackupTarget}
     *
     * @throws RestoreRefused when none holds its bytes
     */
    private function firstVerified(Site $site, AuditActor $actor, array $candidates, array &$warnings): array
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

        throw new RestoreRefused(implode(' ', $failures).' No es restaura res.');
    }

    /**
     * The snapshot of this run's inventory or, when it failed (a broken site),
     * the latest one known.
     */
    private function snapshot(StepContext $context, Site $site): ?SiteSnapshot
    {
        $id = $context->resultOf(InventoryStep::NAME)['snapshot_id'] ?? null;

        return (is_int($id) ? SiteSnapshot::query()->find($id) : null) ?? ShopGuard::latest($site);
    }
}
