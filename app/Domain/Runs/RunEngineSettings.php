<?php

declare(strict_types=1);

namespace App\Domain\Runs;

final readonly class RunEngineSettings
{
    public function __construct(
        public int $runTimeoutSeconds,
        public int $heartbeatSeconds,
        public int $maxStepAttempts,
        public int $summaryMaxChars,
        public int $outputMaxBytes,
    ) {}
}
