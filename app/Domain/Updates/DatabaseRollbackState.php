<?php

declare(strict_types=1);

namespace App\Domain\Updates;

/**
 * What the update's own rollback did to the database (UZEROWPUP-60), for
 * the operator. Never "untouched" when the step failed: a failed import may
 * have left the database half restored.
 */
enum DatabaseRollbackState: string
{
    case Restored = 'restored';
    /** Not restored on purpose: new orders or users since the copy, or it could not be told (rule 4). */
    case NotRestored = 'not_restored';
    /** The step failed: the state of the database is unknown. */
    case Failed = 'failed';
    /** The rollback stopped before reaching the database. */
    case NotReached = 'not_reached';

    public function label(): string
    {
        return match ($this) {
            self::Restored => 'base de dades restaurada',
            self::NotRestored => 'base de dades NO restaurada',
            self::Failed => 'estat de la base de dades desconegut: el rollback de la BD ha fallat, cal revisar-la',
            self::NotReached => 'base de dades no tocada (el rollback s\'ha aturat abans)',
        };
    }
}
