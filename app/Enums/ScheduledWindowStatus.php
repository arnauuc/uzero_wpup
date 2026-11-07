<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What became of a site in one occurrence of its maintenance window
 * (ADR-0006, UZEROWPUP-129). Pending and Launched are open; the others are
 * final for that window.
 */
enum ScheduledWindowStatus: string
{
    /** In the scheduler's queue, waiting for its turn. */
    case Pending = 'pending';
    /** Its update run was queued. */
    case Launched = 'launched';
    /** The run succeeded (it updated something, or found nothing to apply). */
    case Succeeded = 'succeeded';
    /** The run failed or rolled back: not tried again in this window. */
    case Failed = 'failed';
    /** Nothing applicable, or the site cannot be updated automatically: no time spent. */
    case Skipped = 'skipped';
    /** It did not fit in what was left of its window: its next window, with priority. */
    case Deferred = 'deferred';
    /** The site stopped being eligible (automatic updates off, deactivated, blocked) or the run was cancelled. */
    case Cancelled = 'cancelled';

    /**
     * Catalan text for the operator.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'a la cua',
            self::Launched => 'en execució',
            self::Succeeded => 'actualitzat',
            self::Failed => 'ha fallat',
            self::Skipped => 'omès',
            self::Deferred => 'ajornat a la finestra següent',
            self::Cancelled => 'cancel·lat',
        };
    }
}
