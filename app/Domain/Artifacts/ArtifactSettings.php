<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

use InvalidArgumentException;

/**
 * Package artifacts (config wpup.artifacts).
 */
final readonly class ArtifactSettings
{
    public function __construct(
        public string $directory,
        public int $connectTimeoutSeconds,
        public int $timeoutSeconds,
        public int $readTimeoutSeconds,
        public int $maxBytes,
        public int $maxUnpackedBytes,
        public int $maxRedirects,
        public int $chunkBytes,
        public int $commandTimeoutSeconds,
        public string $wordpressOrgPluginUrl,
        public string $wordpressOrgThemeUrl,
        public int $minRetentionDays,
    ) {
        if ($directory === '' || min($connectTimeoutSeconds, $timeoutSeconds, $readTimeoutSeconds, $maxBytes, $maxUnpackedBytes, $chunkBytes, $commandTimeoutSeconds) <= 0 || $maxRedirects < 0) {
            throw new InvalidArgumentException('wpup.artifacts: the directory, limits and timeouts must be set and positive.');
        }
        if ($minRetentionDays < 30) {
            // ADR-0009: artifacts are kept at least 30 days.
            throw new InvalidArgumentException('wpup.artifacts.min_retention_days must be at least 30 (ADR-0009).');
        }
    }
}
