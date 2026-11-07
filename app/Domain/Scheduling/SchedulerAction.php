<?php

declare(strict_types=1);

namespace App\Domain\Scheduling;

enum SchedulerAction: string
{
    /** The site's window opened: it entered the queue. */
    case Enqueued = 'enqueued';
    /** Its update run was queued. */
    case Launched = 'launched';
    case Skipped = 'skipped';
    case Deferred = 'deferred';
    case Cancelled = 'cancelled';
    /** Its run finished: the window's outcome was recorded. */
    case Settled = 'settled';
    /** Another update is running or waiting to start: nothing starts now. */
    case Waiting = 'waiting';
    /** The tool server is short of disk or memory: nothing starts. */
    case Halted = 'halted';
    /** Failed windows in a row: blocked for automatic updates. */
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Enqueued => 'entra a la cua',
            self::Launched => 'actualització encuada',
            self::Skipped => 'omès',
            self::Deferred => 'ajornat a la finestra següent, amb prioritat',
            self::Cancelled => 'cancel·lat',
            self::Settled => 'resultat de la finestra',
            self::Waiting => 'en espera',
            self::Halted => 'planificador aturat',
            self::Blocked => "bloquejat per a l'actualització automàtica",
        };
    }
}
