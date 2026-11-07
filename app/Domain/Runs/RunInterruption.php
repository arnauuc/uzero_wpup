<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use App\Enums\RunStepKind;
use App\Models\Run;
use Carbon\CarbonImmutable;

/**
 * One time a run lost its worker and the engine picked it up again
 * (UZEROWPUP-62), as kept in runs.interruptions: so the operator is always
 * told, with the step and the decision, whatever the run ended as.
 */
final readonly class RunInterruption
{
    /**
     * @param  string  $phase  the run's phase when it resumed (running, rolling_back)
     * @param  string|null  $step  the step it was running, or the next one when it died between steps
     * @param  int  $attempts  attempts the step had already made
     */
    public function __construct(
        public CarbonImmutable $at,
        public string $phase,
        public ?string $step,
        public ?RunStepKind $kind,
        public int $attempts,
        public InterruptionDecision $decision,
    ) {}

    /**
     * @return list<self>
     */
    public static function of(Run $run): array
    {
        $entries = [];
        foreach ($run->interruptions ?? [] as $entry) {
            $decision = InterruptionDecision::tryFrom(is_string($entry['decision'] ?? null) ? $entry['decision'] : '');
            $at = $entry['at'] ?? null;
            if ($decision === null || ! is_string($at)) {
                continue;
            }
            $step = $entry['step'] ?? null;
            $kind = $entry['kind'] ?? null;
            $entries[] = new self(
                CarbonImmutable::parse($at),
                is_string($entry['phase'] ?? null) ? $entry['phase'] : '',
                is_string($step) ? $step : null,
                is_string($kind) ? RunStepKind::tryFrom($kind) : null,
                is_int($entry['attempts'] ?? null) ? $entry['attempts'] : 0,
                $decision,
            );
        }

        return $entries;
    }

    /**
     * @return array{at: string, phase: string, step: string|null, kind: string|null, attempts: int, decision: string}
     */
    public function toArray(): array
    {
        return [
            'at' => $this->at->toIso8601String(),
            'phase' => $this->phase,
            'step' => $this->step,
            'kind' => $this->kind?->value,
            'attempts' => $this->attempts,
            'decision' => $this->decision->value,
        ];
    }

    /** One line for the operator. */
    public function describe(): string
    {
        $where = $this->step === null ? 'entre passos' : 'al pas «'.$this->step.'»'.($this->kind === RunStepKind::Forward || $this->kind === null ? '' : " ({$this->kind->value})");
        $phase = $this->phase === 'rolling_back' ? ' durant el rollback' : '';

        return 'Execució interrompuda el '.$this->at->format('d/m/Y H:i:s')." {$where}{$phase}: {$this->decision->label()}.";
    }
}
