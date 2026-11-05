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
    public function __construct(string $message, public readonly int $status = 0, public readonly string $summary = '')
    {
        parent::__construct($message, $status);
    }

    public static function response(string $endpoint, int $status, string $summary): self
    {
        return new self("Dropbox ha respost HTTP {$status} a {$endpoint}: {$summary}", $status, $summary);
    }

    public static function transport(string $endpoint, string $reason): self
    {
        return new self("No s'ha pogut contactar amb Dropbox ({$endpoint}): {$reason}");
    }

    /** The path (or the source of a move) does not exist. */
    public function isNotFound(): bool
    {
        return str_contains($this->summary, 'not_found');
    }

    /** Something already exists at the path. */
    public function isConflict(): bool
    {
        return str_contains($this->summary, 'conflict');
    }
}
