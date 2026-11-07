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
    /** The run could not be rebuilt to resume it: it fails, with its restore point kept. */
    case Fail = 'fail';

    public function label(): string
    {
        return match ($this) {
            self::RetryStep => 'es torna a executar el pas',
            self::NextStep => 'es continua amb el pas següent',
            self::GiveUp => "massa interrupcions: el pas falla i s'aplica la seva política (rollback o aturada)",
            self::Fail => "no s'ha pogut reprendre: l'execució falla i cal revisar el lloc",
        };
    }
}
