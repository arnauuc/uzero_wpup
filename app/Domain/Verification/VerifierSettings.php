<?php

declare(strict_types=1);

namespace App\Domain\Verification;

use InvalidArgumentException;

/**
 * The verifier process and the defaults of a verification (config wpup.verifier).
 */
final readonly class VerifierSettings
{
    /**
     * @param  list<string>  $command  argument array, never a shell string
     * @param  list<Viewport>  $viewports
     * @param  list<PageTarget>  $urls
     * @param  list<string>  $masks  CSS selectors hidden in the screenshots
     */
    public function __construct(
        public array $command,
        public string $workDir,
        public string $path,
        public ?string $browsersPath,
        public int $timeoutSeconds,
        public int $stepTimeoutSeconds,
        public int $totalMs,
        public int $navigationTimeoutMs,
        public int $maxResultBytes,
        public float $maxDiffRatio,
        public array $viewports,
        public array $urls,
        public array $masks,
    ) {
        if ($command === [] || $workDir === '' || $path === '' || $viewports === [] || $urls === []
            || min($timeoutSeconds, $stepTimeoutSeconds, $totalMs, $navigationTimeoutMs, $maxResultBytes) <= 0) {
            throw new InvalidArgumentException('wpup.verifier: command, paths, viewports, urls, limits and timeouts must be set and positive.');
        }
        // The verifier stops by itself first; the process timeout is the hard stop, inside the step's.
        if ($totalMs >= $timeoutSeconds * 1000 || $timeoutSeconds >= $stepTimeoutSeconds) {
            throw new InvalidArgumentException('wpup.verifier: total_ms < timeout_seconds < step_timeout_seconds.');
        }
    }
}
