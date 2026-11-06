<?php

declare(strict_types=1);

namespace App\Domain\ToolBackup;

use App\Domain\Identity\Ability;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use Carbon\CarbonImmutable;

/**
 * Makes, uploads and verifies the tool's backup, then applies its retention.
 * Running it again after a crash makes a new copy with a new key; the
 * retention keeps only the latest of the day, so nothing piles up.
 */
final readonly class ToolBackupStep implements Step
{
    public const string NAME = 'tool_backup';

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
        $check = $context->resultOf(ToolBackupCheckStep::NAME);
        $key = is_string($check['key'] ?? null) ? $check['key'] : 'tool/…';
        $delete = is_array($check['would_delete'] ?? null) ? $check['would_delete'] : [];

        return "Faria el pg_dump, el xifraria amb age i pujaria «{$key}»; la retenció n'esborraria ".count($delete)
            .($delete === [] ? '.' : ': '.implode(', ', array_filter($delete, is_string(...))).'.');
    }

    public function run(StepContext $context): StepOutcome
    {
        $actor = $this->authorization->actorFor($context->run, Ability::RunOperations);
        if ($actor === null) {
            return StepOutcome::failed(RunAuthorization::deniedMessage($context->run));
        }

        try {
            $result = $this->service->run($actor, CarbonImmutable::now());
        } catch (ToolBackupException $e) {
            return StepOutcome::failed($e->getMessage());
        }

        return StepOutcome::succeeded($result->summary(), implode("\n", $result->warnings), $result->toArray());
    }
}
