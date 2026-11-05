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
    ) {
        if ($chunkBytes <= 0 || $chunkBytes % self::CHUNK_UNIT_BYTES !== 0 || $chunkBytes > self::MAX_CHUNK_BYTES) {
            throw new InvalidArgumentException('wpup.backups.dropbox.chunk_bytes must be a multiple of 4 MiB, at most 150 MiB.');
        }
        if ($maxRetries < 0 || $maxRetryAfterSeconds < 0 || $bufferMemoryBytes <= 0) {
            throw new InvalidArgumentException('wpup.backups.dropbox retries and buffers must not be negative.');
        }
    }
}
