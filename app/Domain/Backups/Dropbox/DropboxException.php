<?php

declare(strict_types=1);

namespace App\Domain\Backups\Dropbox;

use RuntimeException;

/**
 * A failed Dropbox call. Messages are redacted before they get here and never
 * carry tokens or the app secret; the Guzzle exception is not chained, since
 * its message may quote the request or the response.
 */
final class DropboxException extends RuntimeException
{
    /**
     * @param  bool  $afterRetry  the request had already been sent once: an earlier attempt may have succeeded
     * @param  int|null  $correctOffset  upload sessions: the offset Dropbox already holds
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $summary = '',
        public readonly bool $afterRetry = false,
        public readonly ?int $correctOffset = null,
    ) {
        parent::__construct($message, $status);
    }

    public static function response(string $endpoint, int $status, string $summary, bool $afterRetry = false, ?int $correctOffset = null): self
    {
        return new self("Dropbox ha respost HTTP {$status} a {$endpoint}: {$summary}", $status, $summary, $afterRetry, $correctOffset);
    }

    public static function transport(string $endpoint, string $reason): self
    {
        return new self("No s'ha pogut contactar amb Dropbox ({$endpoint}): {$reason}");
    }

    public static function unencodable(string $endpoint): self
    {
        return new self("Els arguments de la crida {$endpoint} a Dropbox no es poden codificar en JSON (text que no és UTF-8?).");
    }

    /** The path (or the source of a move) does not exist. */
    public function isNotFound(): bool
    {
        return str_contains($this->summary, 'not_found');
    }

    /** create_folder_v2: a folder is already there (a file in the way is not this). */
    public function isFolderConflict(): bool
    {
        return str_contains($this->summary, 'conflict/folder');
    }

    /** move_v2/copy_v2: something is already at the destination. */
    public function isDestinationConflict(): bool
    {
        return str_starts_with($this->summary, 'to/conflict');
    }

    /** upload_session: the session is gone (finished, closed or expired). */
    public function isSessionGone(): bool
    {
        return preg_match('#^lookup_failed/(not_found|closed)#', $this->summary) === 1;
    }
}
