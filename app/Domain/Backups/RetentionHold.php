<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * Why a restore point that the retention would purge is kept. The cases are in
 * precedence order: the first one that applies is the one reported, and any of
 * them beats every PurgeReason.
 */
enum RetentionHold: string
{
    /** The point that was just made (purge after creating a point). */
    case JustCreated = 'just_created';

    /** Someone holds the site's lock: a run may be using the point right now. */
    case SiteBusy = 'site_busy';

    /** Marked protected (reverted run or incident) until an operator reviews the run. */
    case Protected = 'protected';

    /** Its run has not finished (queued, running, rolling back or interrupted). */
    case RunInProgress = 'run_in_progress';

    /** Its run was rolled back or failed and nobody has reviewed it yet (`run:ack`). */
    case IncidentNotReviewed = 'incident_not_reviewed';

    /** An unfinished run (a rollback, a restore) was launched from its run. */
    case NeededByRun = 'needed_by_run';

    /** The site has an unfinished run and this is its newest verified point on the site. */
    case NewestOfActiveSite = 'newest_of_active_site';

    public function label(): string
    {
        return match ($this) {
            self::JustCreated => "s'acaba de fer",
            self::SiteBusy => 'el lloc està bloquejat per una altra operació',
            self::Protected => 'protegit fins que es revisi l\'execució',
            self::RunInProgress => 'la seva execució no ha acabat',
            self::IncidentNotReviewed => 'execució revertida o fallida pendent de revisar',
            self::NeededByRun => 'el necessita una execució en curs',
            self::NewestOfActiveSite => 'és el més recent d\'un lloc amb una execució en curs',
        };
    }
}
