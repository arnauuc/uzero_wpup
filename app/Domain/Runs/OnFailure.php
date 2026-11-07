<?php

declare(strict_types=1);

namespace App\Domain\Runs;

/**
 * What the engine does when a forward step fails (UZEROWPUP-60). Set per step
 * name in the Pipeline; Compensate unless the pipeline says otherwise.
 */
enum OnFailure: string
{
    /** Undo through the compensations of the steps that ran, in reverse order (the default). */
    case Compensate = 'compensate';

    /**
     * Run the pipeline's recovery branch, in order, as steps of their own and
     * instead of any compensation: a rollback that must keep a strict order
     * (files, then database, maintenance mode on until the end).
     */
    case Recover = 'recover';

    /**
     * Neither compensate nor recover: the run fails and keeps what it did.
     * For a step that fails after the run's changes were verified (undoing
     * them would be worse than the failure itself).
     */
    case Stop = 'stop';
}
