<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

use InvalidArgumentException;

/**
 * Package artifacts (config wpup.artifacts).
 */
final readonly class ArtifactSettings
{
    /**
     * ADR-0009 safety floor: artifacts are kept at least this long whatever
     * the configuration says. The configured value may only raise it.
     */
    public const int MIN_RETENTION_FLOOR_DAYS = 30;

    /**
     * @param  list<int>  $allowedPorts
     * @param  list<string>  $secretQueryKeys  lowercase; a query key containing one names a secret
     */
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
        public array $allowedPorts,
        public array $secretQueryKeys,
        public int $minSecretValueLength,
        public int $minSecretSegmentLength,
        public string $wordpressOrgCoreUrl,
        public string $wordpressOrgLocalizedCoreUrl,
    ) {
        if ($directory === '' || min($connectTimeoutSeconds, $timeoutSeconds, $readTimeoutSeconds, $maxBytes, $maxUnpackedBytes, $chunkBytes, $commandTimeoutSeconds, $minSecretValueLength, $minSecretSegmentLength) <= 0 || $maxRedirects < 0) {
            throw new InvalidArgumentException('wpup.artifacts: the directory, limits and timeouts must be set and positive.');
        }
        if ($minRetentionDays < self::MIN_RETENTION_FLOOR_DAYS) {
            throw new InvalidArgumentException('wpup.artifacts.min_retention_days must be at least '.self::MIN_RETENTION_FLOOR_DAYS.' (ADR-0009).');
        }
        if ($allowedPorts === [] || $secretQueryKeys === []) {
            throw new InvalidArgumentException('wpup.artifacts: allowed_ports and url_secrets.query_keys must not be empty.');
        }
    }
}
