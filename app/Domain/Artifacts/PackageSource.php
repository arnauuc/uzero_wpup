<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\ComponentType;

/**
 * Where to download one package from, built at download time and never
 * persisted. The URL is a Secret: premium update servers put the licence in
 * the query, the path or the userinfo. Only host() may be shown.
 */
final readonly class PackageSource
{
    private const string NAME = '/^[A-Za-z0-9_][A-Za-z0-9._+-]*$/D';

    private function __construct(
        public ComponentType $type,
        public string $slug,
        public string $version,
        public Secret $url,
        private string $host,
    ) {}

    /**
     * Validates the URL (HTTPS only) and registers every part of it that may
     * be a token with the redactor, before anything can log it.
     *
     * @throws ArtifactException
     */
    public static function create(ComponentType $type, string $slug, string $version, #[\SensitiveParameter] string $url, SecretRedactor $redactor): self
    {
        self::guard($url, $redactor);
        if (preg_match(self::NAME, $slug) !== 1 || preg_match(self::NAME, $version) !== 1) {
            throw ArtifactException::noPackage($slug, $version, 'el nom o la versió tenen caràcters no permesos.');
        }
        $host = self::httpsHost($url);
        if ($host === null) {
            throw ArtifactException::noPackage($slug, $version, "l'URL no és HTTPS.");
        }

        return new self($type, $slug, $version, new Secret($url), $host);
    }

    public function host(): string
    {
        return $this->host;
    }

    /** "plugin-woocommerce-10.2.0.zip": the file name in the run's directory. */
    public function fileName(): string
    {
        return "{$this->type->value}-{$this->slug}-{$this->version}.zip";
    }

    /**
     * Registers the URL and its token-looking parts: the whole URL, the query
     * and each of its values, the userinfo, and path segments that look like
     * keys (long, with a digit; the .zip file name excepted).
     */
    public static function guard(#[\SensitiveParameter] string $url, SecretRedactor $redactor): void
    {
        $redactor->register($url);
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return;
        }
        foreach (['user', 'pass', 'query', 'fragment'] as $key) {
            if (isset($parts[$key])) {
                $redactor->register($parts[$key]);
                $redactor->register(rawurldecode($parts[$key]));
            }
        }
        parse_str($parts['query'] ?? '', $query);
        array_walk_recursive($query, static fn (mixed $value) => is_string($value) ? $redactor->register($value) : null);
        foreach (explode('/', $parts['path'] ?? '') as $segment) {
            $segment = rawurldecode($segment);
            if (strlen($segment) >= 16 && preg_match('/\d/', $segment) === 1 && ! str_ends_with(strtolower($segment), '.zip')) {
                $redactor->register($segment);
            }
        }
    }

    /** The lowercase host of an https:// URL, or null when it is not one. */
    public static function httpsHost(#[\SensitiveParameter] string $url): ?string
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';

        return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https' && $host !== '' ? strtolower($host) : null;
    }
}
