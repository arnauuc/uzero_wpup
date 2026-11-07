<?php

declare(strict_types=1);

namespace App\Domain\Runs;

/**
 * What the engine did with a run that lost its worker (UZEROWPUP-62, see
 * docs/runbooks/represa-actualitzacions.md).
 */
enum InterruptionDecision: string
{
    /** The worker died during a step: it is run again (every step is idempotent). */
    case RetryStep = 'retry_step';
    /** The worker died between two steps: the run goes on with the next one. */
    case NextStep = 'next_step';
    /** The step was interrupted max_step_attempts times: it fails, and its failure policy decides (rollback or stop). */
    case GiveUp = 'give_up';
    /** The run had a failed step not handled yet (or one the code no longer has): its failure policy applies (rollback or stop). */
    case ApplyFailurePolicy = 'apply_failure_policy';
    /** The run's deadline passed meanwhile: the next step fails and its failure policy applies. */
    case Deadline = 'deadline';
    /** Every step had finished: the run is closed as it ended. */
    case Finish = 'finish';
    /** The run could not be rebuilt to resume it, or its rollback had already failed: it fails, with its restore point kept. */
    case Fail = 'fail';

    public function label(): string
    {
        return match ($this) {
            self::RetryStep => 'es torna a executar el pas',
            self::NextStep => 'es continua amb el pas següent',
            self::GiveUp => "massa interrupcions: el pas falla i s'aplica la seva política (rollback o aturada)",
            self::ApplyFailurePolicy => "el pas no es pot continuar: s'aplica la seva política en cas de fallada (rollback o aturada)",
            self::Deadline => "s'ha superat el termini de l'execució: el pas falla i s'aplica la seva política (rollback o aturada)",
            self::Finish => "tots els passos s'havien acabat: es tanca l'execució",
            self::Fail => "no s'ha pogut reprendre: l'execució falla i cal revisar el lloc",
        };
    }
}
