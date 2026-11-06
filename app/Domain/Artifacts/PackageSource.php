<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

use App\Domain\Secrets\Secret;
use App\Enums\ComponentType;

/**
 * Where to download one package from, built at download time and never
 * persisted. The URL is a Secret: premium update servers put the licence in
 * the query, the path or the userinfo. Only host() may be shown.
 */
final readonly class PackageSource
{
    /** Slugs and versions; "~" is outside it, so fileName() is unambiguous. */
    private const string NAME = '/^[A-Za-z0-9_][A-Za-z0-9._+-]*$/D';

    private function __construct(
        public ComponentType $type,
        public string $slug,
        public string $version,
        public Secret $url,
        private string $host,
    ) {}

    /**
     * Registers the URL's token-looking parts with the redactor (before
     * anything can log it) and validates it: HTTPS only. Whether its host
     * may be reached is checked when it is downloaded (PackageUrlGuard::pin).
     *
     * @throws ArtifactException
     */
    public static function create(ComponentType $type, string $slug, string $version, #[\SensitiveParameter] string $url, PackageUrlGuard $guard): self
    {
        $guard->register($url, $slug, $version);
        if (preg_match(self::NAME, $slug) !== 1 || preg_match(self::NAME, $version) !== 1) {
            throw ArtifactException::noPackage($slug, $version, 'el nom o la versió tenen caràcters no permesos.');
        }
        $host = PackageUrlGuard::httpsHost($url);
        if ($host === null) {
            throw ArtifactException::noPackage($slug, $version, "l'URL no és HTTPS.");
        }

        return new self($type, $slug, $version, new Secret($url), $host);
    }

    public function host(): string
    {
        return $this->host;
    }

    /** "plugin~woocommerce~10.2.0.zip": the file name in the run's directory. */
    public function fileName(): string
    {
        return "{$this->type->value}~{$this->slug}~{$this->version}.zip";
    }
}
