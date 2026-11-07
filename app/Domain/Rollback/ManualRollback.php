<?php

declare(strict_types=1);

namespace App\Domain\Rollback;

use App\Domain\Backups\BackupListing;
use App\Domain\Backups\RestorePointRetention;
use App\Domain\Backups\RestoreRefused;
use App\Domain\Backups\RestoreSelection;
use App\Domain\Updates\UpdateChanges;
use App\Enums\BackupKind;
use App\Enums\ChangeResult;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Backup;
use App\Models\Run;
use App\Models\Site;
use Carbon\CarbonImmutable;

/**
 * Whether an update run can be rolled back by hand (`wpup rollback <run>`,
 * UZEROWPUP-61), from what the tool database knows. The command asks before
 * queueing and RollbackCheckStep asks again before anything is written (it
 * also checks each copy's SHA-256 at its destination, which the CLI cannot:
 * ADR-0011). RollbackSource already refused a run that is not an update of
 * the site or that has a later update still applied.
 *
 * Refused: an update that has not finished, that was rolled back already
 * (by itself or by an earlier manual rollback that succeeded) or that
 * changed nothing (a rollback that failed half way can be retried); one
 * with another manual rollback in progress; and one whose restore point has
 * no live, verified and unexpired copy of the files and of the database.
 */
final readonly class ManualRollback
{
    public function __construct(private RestoreSelection $selection) {}

    /**
     * @param  Run|null  $rollback  the rollback run asking (left out of the other rollbacks)
     *
     * @throws RollbackRefused
     */
    public function assertRevertible(Run $source, ?Run $rollback = null): void
    {
        if (! $source->status->isTerminal()) {
            throw new RollbackRefused("L'actualització #{$source->id} encara no ha acabat ({$source->status->value}): espera que acabi.");
        }
        if ($source->status === RunStatus::RolledBack) {
            throw new RollbackRefused("L'actualització #{$source->id} ja es va revertir ella mateixa: no hi ha res a desfer.");
        }

        $others = Run::query()->where('parent_run_id', $source->id)->where('type', RunType::Rollback->value)
            ->when($rollback !== null, static fn ($q) => $q->whereKeyNot($rollback?->id))
            ->orderBy('id')->get()
            ->filter(static fn (Run $r): bool => ($r->flags['dry_run'] ?? false) !== true);
        foreach ($others as $other) {
            if (! $other->status->isTerminal()) {
                throw new RollbackRefused("Ja hi ha el rollback #{$other->id} de l'actualització #{$source->id} en curs.");
            }
            if ($other->status === RunStatus::Succeeded) {
                throw new RollbackRefused("L'actualització #{$source->id} ja es va revertir amb el rollback #{$other->id}.");
            }
        }

        // Changes still applied, or undone by a rollback that did not finish its work (it
        // failed after the files, e.g. in the database): that one may be tried again.
        $results = [ChangeResult::Updated->value, ChangeResult::Failed->value];
        if ($source->reverted_at !== null) {
            $results[] = ChangeResult::Reverted->value;
        }
        $applied = $source->changes()->where('step', UpdateChanges::STEP)->whereIn('result', $results)->exists();
        if (! $applied) {
            throw new RollbackRefused("L'actualització #{$source->id} no va aplicar cap canvi (o ja estan tots revertits): no hi ha res a desfer.");
        }
    }

    /**
     * The usable copies of the update's own restore point, by kind (files,
     * then database), the site-local one first: live, verified at their
     * destination and not expired, or kept past their expiry by the retention
     * (protected by hand, or of an update that is an unreviewed incident:
     * RestorePointRetention::heldForReview()).
     *
     * @return array<string, non-empty-list<Backup>>
     *
     * @throws RollbackRefused
     */
    public function copies(Site $site, Run $source): array
    {
        $now = CarbonImmutable::now();
        $held = RestorePointRetention::heldForReview($source);
        $copies = [];
        foreach ([BackupKind::Files, BackupKind::Database] as $kind) {
            try {
                $rows = $this->selection->ofPoint($site, (string) $source->id, $kind);
            } catch (RestoreRefused $e) {
                throw new RollbackRefused("El punt de restauració de l'actualització #{$source->id} no es pot fer servir: {$e->getMessage()}");
            }
            $live = array_values(array_filter($rows, static fn (Backup $row): bool => $held || $row->protected || $row->expires_at === null || $row->expires_at->greaterThan($now)));
            if ($live === []) {
                $expired = $rows[0]->expires_at?->format(BackupListing::dateFormat()) ?? '?';

                throw new RollbackRefused('La còpia '.RestoreSelection::kindLabel($kind)." del punt de restauració de l'actualització #{$source->id} va caducar el {$expired}: "
                    .'ja no es fa servir per a un rollback (ADR-0009).');
            }
            $copies[$kind->value] = $live;
        }

        return $copies;
    }
}
