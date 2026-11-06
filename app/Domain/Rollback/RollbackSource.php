<?php

declare(strict_types=1);

namespace App\Domain\Rollback;

use App\Enums\RunType;
use App\Models\Run;

/**
 * The update run a rollback undoes (UZEROWPUP-57): the run itself when the
 * rollback is part of its own update (UZEROWPUP-60), or the parent of a
 * `rollback` run (`wpup rollback <run>`, UZEROWPUP-61), which must be of the
 * same site so that it holds that site's lock.
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

        return $source;
    }
}
