<?php

declare(strict_types=1);

namespace App\Domain\Backups\Dropbox;

use InvalidArgumentException;

/**
 * How the tool talks to Dropbox (config wpup.backups.dropbox).
 */
final readonly class DropboxSettings
{
    /** Dropbox requires every upload-session chunk but the last to be a multiple of 4 MiB. */
    public const int CHUNK_UNIT_BYTES = 4 * 1024 * 1024;

    /** Dropbox refuses more than 150 MiB in one request. */
    public const int MAX_CHUNK_BYTES = 150 * 1024 * 1024;

    public function __construct(
        public string $apiUrl,
        public string $contentUrl,
        public string $tokenUrl,
        public int $chunkBytes,
        public int $maxRetries,
        public int $maxRetryAfterSeconds,
        public int $bufferMemoryBytes,
        public int $connectTimeoutSeconds,
        public int $timeoutSeconds,
        public int $maxErrorBytes,
        public int $errorSummaryChars,
        public int $maxTokenResponseBytes,
        public int $readTimeoutSeconds,
    ) {
        if ($chunkBytes <= 0 || $chunkBytes % self::CHUNK_UNIT_BYTES !== 0 || $chunkBytes > self::MAX_CHUNK_BYTES) {
            throw new InvalidArgumentException('wpup.backups.dropbox.chunk_bytes must be a multiple of 4 MiB, at most 150 MiB.');
        }
        if ($maxRetries < 0 || $maxRetryAfterSeconds < 0 || min($bufferMemoryBytes, $maxErrorBytes, $errorSummaryChars, $maxTokenResponseBytes, $readTimeoutSeconds) <= 0) {
            throw new InvalidArgumentException('wpup.backups.dropbox retries and buffers must not be negative.');
        }
    }

    /**
     * Guzzle timeouts of one request. A download is one request however big
     * the archive: it only has the idle timeout (and resumes with Range).
     *
     * @return array{connect_timeout: int, timeout: int, read_timeout: int, curl: array<int, int>}
     */
    public function timeouts(bool $download = false): array
    {
        return [
            'connect_timeout' => $this->connectTimeoutSeconds,
            'timeout' => $download ? 0 : $this->timeoutSeconds,
            // Idle timeout: read_timeout for the stream handler, low speed for curl.
            'read_timeout' => $this->readTimeoutSeconds,
            'curl' => [CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => $this->readTimeoutSeconds],
        ];
    }
}
