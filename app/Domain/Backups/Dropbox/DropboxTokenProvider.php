<?php

declare(strict_types=1);

namespace App\Domain\Backups\Dropbox;

use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretRedactor;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * Short-lived access tokens obtained with the refresh token of the
 * credential. The access token lives only in memory, and every secret it
 * handles is registered with the redactor so it is masked anywhere.
 */
final class DropboxTokenProvider
{
    private const string ENDPOINT = 'oauth2/token';

    private ?Secret $accessToken = null;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly DropboxCredential $credential,
        private readonly SecretRedactor $redactor,
        private readonly DropboxSettings $settings,
        private readonly DropboxRetryPolicy $retry,
    ) {
        $redactor->register($credential->appSecret);
        $redactor->register($credential->refreshToken);
    }

    public function token(): string
    {
        return ($this->accessToken ?? $this->refresh())->reveal();
    }

    /**
     * Exchanges the refresh token for a new access token.
     *
     * @throws DropboxException
     */
    public function refresh(): Secret
    {
        $this->accessToken?->wipe();
        $this->accessToken = null;

        $response = $this->request();
        $data = json_decode(Utils::copyToString($response->getBody(), $this->settings->maxTokenResponseBytes), true);
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;
        if ($response->getStatusCode() !== 200 || ! is_string($token) || $token === '') {
            $error = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : 'resposta inesperada';

            throw new DropboxException($this->redactor->redact(
                "No s'ha pogut renovar el token d'accés de Dropbox (HTTP {$response->getStatusCode()}: {$error}).",
            ), $response->getStatusCode(), $error);
        }

        $this->redactor->register($token);

        return $this->accessToken = new Secret($token);
    }

    private function request(): ResponseInterface
    {
        for ($retry = 1; ; $retry++) {
            try {
                $response = $this->http->request('POST', $this->settings->tokenUrl, $this->settings->timeouts() + [
                    'http_errors' => false,
                    'form_params' => [
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $this->credential->refreshToken->reveal(),
                        'client_id' => $this->credential->appKey,
                        'client_secret' => $this->credential->appSecret->reveal(),
                    ],
                ]);
            } catch (TransferException $e) {
                if ($this->retry->allows($retry, self::ENDPOINT, $e->getMessage())) {
                    continue;
                }

                throw DropboxException::transport(self::ENDPOINT, $this->redactor->redact($e->getMessage()));
            }

            if (! DropboxRetryPolicy::retryableStatus($response->getStatusCode())
                || ! $this->retry->allows($retry, self::ENDPOINT, 'HTTP '.$response->getStatusCode(), $response->getHeaderLine('Retry-After'))) {
                return $response;
            }
        }
    }
}
