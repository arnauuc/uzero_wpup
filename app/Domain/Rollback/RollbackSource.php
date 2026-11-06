<?php

declare(strict_types=1);

namespace App\Domain\Rollback;

use App\Domain\Updates\UpdateChanges;
use App\Enums\ChangeResult;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Run;

/**
 * The update run a rollback undoes (UZEROWPUP-57): the run itself when the
 * rollback is part of its own update (UZEROWPUP-60), or the parent of a
 * `rollback` run (`wpup rollback <run>`, UZEROWPUP-61), which must be of the
 * same site so that it holds that site's lock.
 *
 * Never an update with a later update of the same site still in place:
 * restoring the older code would silently undo the later one too. A later
 * update counts as undone when it was rolled back or none of its changes is
 * still applied (all reverted, or it applied none); one cancelled or skipped
 * changed nothing.
 */
final class RollbackSource
{
    /**
     * @throws RollbackRefused
     */
    public static function of(Run $run): Run
    {
        $source = $run->type === RunType::Rollback ? $run->parent : $run;
        if ($source === null) {
            throw new RollbackRefused("L'execució #{$run->id} no indica quina actualització s'ha de desfer.");
        }
        if ($source->type !== RunType::Update) {
            throw new RollbackRefused("L'execució #{$source->id} no és una actualització: no hi ha res a desfer.");
        }
        if ($source->site_id === null || $source->site_id !== $run->site_id) {
            throw new RollbackRefused("L'execució #{$source->id} no és del mateix lloc que el rollback.");
        }

        $later = Run::query()
            ->where('site_id', $source->site_id)
            ->where('type', RunType::Update->value)
            ->where('id', '>', $source->id)
            ->whereNotIn('status', [RunStatus::RolledBack->value, RunStatus::Cancelled->value, RunStatus::Skipped->value])
            ->where(static fn ($q) => $q
                ->whereNotIn('status', [RunStatus::Succeeded->value, RunStatus::Failed->value])
                ->orWhereHas('changes', static fn ($c) => $c->where('step', UpdateChanges::STEP)->where('result', ChangeResult::Updated->value)))
            ->orderBy('id')
            ->first();
        if ($later !== null) {
            throw new RollbackRefused("Després de l'execució #{$source->id} hi ha l'actualització #{$later->id} del mateix lloc, que no s'ha revertit: "
                ."desfer la #{$source->id} també en desfaria els canvis. Reverteix primer la #{$later->id}.");
        }

        return $source;
    }
}
