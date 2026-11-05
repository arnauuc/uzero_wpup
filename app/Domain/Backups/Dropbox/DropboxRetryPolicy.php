<?php

declare(strict_types=1);

namespace App\Domain\Backups\Dropbox;

use App\Domain\Secrets\SecretRedactor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Bounded retries of Dropbox calls answered 429/5xx or cut off: at most
 * maxRetries, pausing Retry-After (or an exponential backoff) capped at
 * maxRetryAfterSeconds. Every retried failure is logged, redacted.
 */
final readonly class DropboxRetryPolicy
{
    public function __construct(private DropboxSettings $settings, private SecretRedactor $redactor) {}

    public static function retryableStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /**
     * Logs the failure and pauses when retry number $retry is allowed.
     *
     * @param  string  $reason  HTTP status or transport error; never a response body
     */
    public function allows(int $retry, string $endpoint, string $reason, string $retryAfter = ''): bool
    {
        if ($retry > $this->settings->maxRetries) {
            return false;
        }

        Log::warning('Dropbox call failed; retrying.', [
            'endpoint' => $endpoint,
            'reason' => $this->redactor->redact($reason),
            'retry' => $retry,
            'max_retries' => $this->settings->maxRetries,
        ]);
        $seconds = ctype_digit($retryAfter) ? (int) $retryAfter : 2 ** ($retry - 1);
        Sleep::sleep(min($seconds, $this->settings->maxRetryAfterSeconds));

        return true;
    }

    /**
     * A failure the caller recovered from on its own (an upload resumed, a
     * move that had already happened).
     */
    public function recovered(string $endpoint, string $how): void
    {
        Log::warning('Dropbox call recovered after a lost response.', ['endpoint' => $endpoint, 'how' => $how]);
    }
}
