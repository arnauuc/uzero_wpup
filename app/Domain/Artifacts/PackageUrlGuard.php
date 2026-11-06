<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

use App\Domain\Secrets\SecretRedactor;

/**
 * The two checks every package URL (and every redirect hop) goes through:
 *
 * - register(): its token-looking parts go to the redactor before anything
 *   can log them: the whole URL, userinfo, fragment, values of query keys
 *   that name a secret (config), long high-entropy query values and path
 *   segments. Never the component's slug or version, nor URLs (the site's).
 * - pin(): HTTPS on an allowed port, resolved here to public addresses only
 *   (no loopback, LAN, tailnet…), and the address to pin for the request
 *   (CURLOPT_RESOLVE), so DNS rebinding cannot change it after the check.
 */
final readonly class PackageUrlGuard
{
    private const string TOKEN = '#^[A-Za-z0-9_\-+=/.~%]+$#D';

    public function __construct(
        private SecretRedactor $redactor,
        private HostResolver $resolver,
        private ArtifactSettings $settings,
    ) {}

    public function register(#[\SensitiveParameter] string $url, string ...$keep): void
    {
        $this->redactor->register($url);
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return;
        }
        foreach (['user', 'pass', 'fragment'] as $key) {
            if (isset($parts[$key])) {
                $this->redactor->register($parts[$key]);
                $this->redactor->register(rawurldecode($parts[$key]));
            }
        }

        parse_str($parts['query'] ?? '', $query);
        foreach ($query as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (is_string($item) && ! in_array($item, $keep, true) && ($this->isSecretKey((string) $key) || $this->looksLikeToken($item, $this->settings->minSecretValueLength))) {
                    $this->redactor->register($item);
                }
            }
        }
        foreach (explode('/', $parts['path'] ?? '') as $segment) {
            $segment = rawurldecode($segment);
            if (! in_array($segment, $keep, true) && ! str_ends_with(strtolower($segment), '.zip') && $this->looksLikeToken($segment, $this->settings->minSecretSegmentLength)) {
                $this->redactor->register($segment);
            }
        }
    }

    /** The lowercase host of an https:// URL, or null when it is not one. */
    public static function httpsHost(#[\SensitiveParameter] string $url): ?string
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';

        return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https' && $host !== '' ? strtolower(trim($host, '[]')) : null;
    }

    /**
     * @return string the CURLOPT_RESOLVE entry ("host:port:address")
     *
     * @throws DownloadBlocked
     */
    public function pin(#[\SensitiveParameter] string $url): string
    {
        $host = self::httpsHost($url);
        if ($host === null) {
            throw new DownloadBlocked('not an HTTPS URL');
        }
        $port = parse_url($url, PHP_URL_PORT) ?? 443;
        if (! in_array($port, $this->settings->allowedPorts, true)) {
            throw new DownloadBlocked("port {$port} of {$host} is not allowed");
        }
        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new DownloadBlocked("{$host} does not resolve");
        }
        foreach ($addresses as $address) {
            if (! PublicAddress::isPublic($address)) {
                throw new DownloadBlocked("{$host} resolves to a non-public address");
            }
        }
        $v4 = array_values(array_filter($addresses, static fn (string $a): bool => filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));
        $address = $v4[0] ?? $addresses[0];

        return "{$host}:{$port}:".(str_contains($address, ':') ? "[{$address}]" : $address);
    }

    private function isSecretKey(string $key): bool
    {
        $key = strtolower($key);
        foreach ($this->settings->secretQueryKeys as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Long, letters and digits only from a token alphabet, and not a URL. */
    private function looksLikeToken(string $value, int $minLength): bool
    {
        return strlen($value) >= $minLength
            && preg_match(self::TOKEN, $value) === 1
            && preg_match('/[A-Za-z]/', $value) === 1
            && preg_match('/\d/', $value) === 1;
    }
}
