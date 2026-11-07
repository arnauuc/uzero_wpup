<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;

/**
 * No update without a verified restore point (AGENTS.md rule 3, ADR-0009):
 * the update run (UZEROWPUP-60) asks RestorePointGate right before its first
 * change to the site. Reads only the tool's database; idempotent.
 */
final readonly class RestorePointGateStep implements Step
{
    public const string NAME = 'restore-point-gate';

    public function __construct(private RestorePointGate $gate, private int $timeoutSeconds) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function describe(StepContext $context): string
    {
        return "Comprovarà que l'execució té un punt de restauració verificat (volcat i arxiu del codi amb el SHA-256 comprovat al servidor del lloc) abans de tocar res.";
    }

    public function run(StepContext $context): StepOutcome
    {
        try {
            $this->gate->assertVerified($context->run);
        } catch (RestorePointNotVerified $e) {
            return StepOutcome::failed($e->getMessage());
        }

        return StepOutcome::succeeded('Punt de restauració verificat.');
    }
}
