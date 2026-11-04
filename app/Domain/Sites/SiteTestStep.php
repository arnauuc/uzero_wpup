<?php

declare(strict_types=1);

namespace App\Domain\Sites;

use App\Domain\Drivers\Data\ProbeCheck;
use App\Domain\Drivers\Data\ProbeResult;
use App\Domain\Drivers\DriverFactory;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Identity\Ability;
use App\Domain\Runs\ReadOnlyStep;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;
use App\Enums\CheckResult;

/**
 * `site:test` on the worker (ADR-0011): connecting needs the site's credential,
 * and only the workers can reveal it. Read-only; the checks go back to the CLI
 * in the step result. A failed check is a result, not a failed step.
 */
final readonly class SiteTestStep implements ReadOnlyStep
{
    public const string NAME = 'site_test';

    public function __construct(
        private DriverFactory $drivers,
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
        return 'Provarà la connexió i els prerequisits del lloc sense modificar-lo.';
    }

    public function run(StepContext $context): StepOutcome
    {
        $actor = $this->authorization->actorFor($context->run, Ability::RunOperations);
        if ($actor === null) {
            return StepOutcome::failed(RunAuthorization::deniedMessage($context->run));
        }

        $site = $context->run->site()->firstOrFail();

        try {
            $result = $this->drivers->forSite($site, $actor)->probe();
        } catch (DriverException $e) {
            $result = new ProbeResult([new ProbeCheck('connexió', CheckResult::Error, $e->getMessage())]);
        }

        return StepOutcome::succeeded("Prova del lloc: {$result->worst()->value}", '', [
            'checks' => array_map(static fn (ProbeCheck $check): array => [
                'name' => $check->name,
                'result' => $check->result->value,
                'detail' => $check->detail,
            ], $result->checks),
        ]);
    }

    /**
     * Rebuilds the probe result from the step data stored by run().
     *
     * @param  array<array-key, mixed>|null  $data
     */
    public static function resultFrom(?array $data): ProbeResult
    {
        $checks = [];
        foreach (is_array($data['checks'] ?? null) ? $data['checks'] : [] as $check) {
            if (is_array($check) && is_string($check['name'] ?? null) && is_string($check['detail'] ?? null) && is_string($check['result'] ?? null)) {
                $checks[] = new ProbeCheck($check['name'], CheckResult::tryFrom($check['result']) ?? CheckResult::Error, $check['detail']);
            }
        }

        return new ProbeResult($checks);
    }
}
