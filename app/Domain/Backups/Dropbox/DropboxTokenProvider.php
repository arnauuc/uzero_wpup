<?php

declare(strict_types=1);

namespace App\Domain\Backups\Dropbox;

use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretRedactor;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Utils;

/**
 * Short-lived access tokens obtained with the refresh token of the
 * credential. The access token lives only in memory, and every secret it
 * handles is registered with the redactor so it is masked anywhere.
 */
final class DropboxTokenProvider
{
    private const int MAX_RESPONSE_BYTES = 65_536;

    private ?Secret $accessToken = null;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly DropboxCredential $credential,
        private readonly SecretRedactor $redactor,
        private readonly string $tokenUrl,
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

        try {
            $response = $this->http->request('POST', $this->tokenUrl, [
                'http_errors' => false,
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $this->credential->refreshToken->reveal(),
                    'client_id' => $this->credential->appKey,
                    'client_secret' => $this->credential->appSecret->reveal(),
                ],
            ]);
        } catch (GuzzleException $e) {
            throw DropboxException::transport('oauth2/token', $this->redactor->redact($e->getMessage()));
        }

        $data = json_decode(Utils::copyToString($response->getBody(), self::MAX_RESPONSE_BYTES), true);
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
}
