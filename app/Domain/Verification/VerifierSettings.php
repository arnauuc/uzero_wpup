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
     * @param  list<Viewport>  $viewports
     * @param  list<PageTarget>  $urls
     * @param  list<string>  $masks  CSS selectors hidden in the screenshots
     */
    public function __construct(
        public string $nodeBinary,
        public string $script,
        public string $setsidBinary,
        public string $workDir,
        public string $path,
        public ?string $browsersPath,
        public int $totalMs,
        public int $navigationTimeoutMs,
        public int $screenshotTimeoutMs,
        public int $timeoutSeconds,
        public int $stopGraceSeconds,
        public int $stepTimeoutSeconds,
        public int $maxResultBytes,
        public int $maxStdoutBytes,
        public int $maxStderrBytes,
        public float $maxDiffRatio,
        public array $viewports,
        public array $urls,
        public array $masks,
    ) {
        if ($nodeBinary === '' || $script === '' || $setsidBinary === '' || $workDir === '' || $path === '' || $viewports === [] || $urls === []
            || min($totalMs, $navigationTimeoutMs, $screenshotTimeoutMs, $timeoutSeconds, $stopGraceSeconds, $stepTimeoutSeconds, $maxResultBytes, $maxStdoutBytes, $maxStderrBytes) <= 0) {
            throw new InvalidArgumentException('wpup.verifier: binaries, paths, viewports, urls, limits and timeouts must be set and positive.');
        }
        // The last page may start just before total_ms and take its navigation and its
        // screenshot: the verifier still ends before the hard stop, and the hard stop
        // (with its grace) inside the step.
        if ($totalMs + $navigationTimeoutMs + $screenshotTimeoutMs >= $timeoutSeconds * 1000 || $timeoutSeconds + $stopGraceSeconds >= $stepTimeoutSeconds) {
            throw new InvalidArgumentException('wpup.verifier: total_ms + navigation_timeout_ms + screenshot_timeout_ms < timeout_seconds, and timeout_seconds + stop_grace_seconds < step_timeout_seconds.');
        }
    }
}
