<?php

declare(strict_types=1);

namespace App\Domain\Backups\Dropbox;

use App\Domain\Secrets\SecretRedactor;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Checks a credential against Dropbox with one refresh of the access token,
 * through the same Guzzle setup as the destination itself. Nothing is stored.
 */
final readonly class DropboxCredentialVerifier
{
    /**
     * @param  (Closure(RequestInterface, array<mixed>): PromiseInterface)|null  $handler  Guzzle handler; tests pass a fake API
     */
    public function __construct(
        private SecretRedactor $redactor,
        private DropboxSettings $settings,
        private ?Closure $handler = null,
    ) {}

    /**
     * @throws DropboxException with a redacted message
     */
    public function verify(DropboxCredential $credential): void
    {
        $http = new Client($this->handler === null ? [] : ['handler' => HandlerStack::create($this->handler)]);
        $provider = new DropboxTokenProvider($http, $credential, $this->redactor, $this->settings, new DropboxRetryPolicy($this->settings, $this->redactor));

        $provider->refresh()->wipe();
    }
}
