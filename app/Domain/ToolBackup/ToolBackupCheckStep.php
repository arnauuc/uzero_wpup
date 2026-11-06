<?php

declare(strict_types=1);

namespace App\Domain\ToolBackup;

use App\Domain\Identity\Ability;
use App\Domain\Runs\ReadOnlyStep;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use Carbon\CarbonImmutable;

/**
 * First step of a tool backup run, also under --dry-run: checks the age
 * recipients and the destination, and reports what would be included and
 * what the retention would delete. Changes nothing.
 */
final readonly class ToolBackupCheckStep implements ReadOnlyStep
{
    public const string NAME = 'tool_backup_check';

    public function __construct(
        private ToolBackupService $service,
        private RunAuthorization $authorization,
        private int $timeoutSeconds,
    ) {}

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
        return "Comprovarà els destinataris d'age i la destinació de la còpia de l'eina.";
    }

    public function run(StepContext $context): StepOutcome
    {
        $actor = $this->authorization->actorFor($context->run, Ability::RunOperations);
        if ($actor === null) {
            return StepOutcome::failed(RunAuthorization::deniedMessage($context->run));
        }

        try {
            $preview = $this->service->preview($actor, CarbonImmutable::now());
        } catch (ToolBackupException $e) {
            return StepOutcome::failed($e->getMessage());
        }

        return StepOutcome::succeeded($preview->summary(), implode("\n", $preview->warnings), $preview->toArray());
    }
}
