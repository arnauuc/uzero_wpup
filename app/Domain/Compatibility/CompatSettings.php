<?php

declare(strict_types=1);

namespace App\Domain\Compatibility;

use InvalidArgumentException;

/**
 * Static compatibility analysis (config wpup.static_analysis).
 */
final readonly class CompatSettings
{
    /**
     * @param  list<string>  $ignore  phpcs --ignore patterns
     */
    public function __construct(
        public string $workDir,
        public string $phpcsBinary,
        public string $standardPath,
        public int $timeoutSeconds,
        public string $memoryLimit,
        public int $maxOutputBytes,
        public int $maxFiles,
        public array $ignore,
        public int $summaryMaxFindings,
        public int $summaryMaxPathLength,
        public int $summaryMaxMessageLength,
        public int $detailMaxFindings,
        public int $warningsMax,
        /** Sum of the uncompressed sizes of the extracted files (wpup.artifacts.max_unpacked_bytes). */
        public int $maxUnpackedBytes,
    ) {
        if ($workDir === '' || $phpcsBinary === '' || $standardPath === '' || preg_match('/^\d+[KMG]?$/D', $memoryLimit) !== 1
            || min($timeoutSeconds, $maxOutputBytes, $maxFiles, $summaryMaxFindings, $summaryMaxPathLength, $summaryMaxMessageLength, $detailMaxFindings, $warningsMax, $maxUnpackedBytes) <= 0) {
            throw new InvalidArgumentException('wpup.static_analysis: paths, limits and timeouts must be set and positive.');
        }
        foreach ($ignore as $pattern) {
            // phpcs --ignore is a comma-separated list: a comma would split a pattern.
            if ($pattern === '' || str_contains($pattern, ',')) {
                throw new InvalidArgumentException('wpup.static_analysis.ignore: patterns must be non-empty and contain no commas.');
            }
        }
    }
}
