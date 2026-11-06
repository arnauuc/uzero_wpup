<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\PendingUpdate;
use App\Domain\Inventory\PendingUpdates;
use App\Domain\Inventory\WpCliReader;
use App\Enums\ComponentType;

/**
 * The download URL of a pending update, read at download time and never
 * stored (the snapshot only keeps the host, PendingUpdates):
 *
 * - core, WordPress.org plugins and themes: downloads.wordpress.org, built
 *   from config with the slug, the version and (core) the site's locale;
 *   never an URL the site gives;
 * - premium components: the `package` of the site's update_plugins or
 *   update_themes transient, where the site's registered licence put it.
 *
 * The site's output is never quoted in a message: it holds every package URL.
 * A lost connection propagates; any other failure of the site is noPackage.
 */
final readonly class PackageUrlResolver
{
    private const string LOCALE = '/^[a-z]{2,3}(_[A-Z]{2})?$/D';

    private const string WPORG_SLUG = '/^[a-z0-9][a-z0-9._-]*$/D';

    private const string VERSION = '/^[A-Za-z0-9][A-Za-z0-9._+-]*$/D';

    public function __construct(
        private WpCliReader $reader,
        private PackageUrlGuard $guard,
        private ArtifactSettings $settings,
    ) {}

    /**
     * @throws ArtifactException
     */
    public static function componentType(PendingUpdate $update): ComponentType
    {
        $type = ComponentType::tryFrom($update->type);

        return in_array($type, [ComponentType::Core, ComponentType::Plugin, ComponentType::Woocommerce, ComponentType::Theme], true)
            ? $type
            : throw ArtifactException::noPackage($update->slug, $update->newVersion, "tipus de component no admès «{$update->type}».");
    }

    /**
     * @throws ArtifactException
     * @throws ConnectionFailed
     */
    public function resolve(SiteDriver $driver, PendingUpdate $update): PackageSource
    {
        $type = self::componentType($update);
        if (preg_match(self::VERSION, $update->newVersion) !== 1) {
            throw ArtifactException::noPackage($update->slug, $update->newVersion, 'la versió no és vàlida.');
        }
        $url = match (true) {
            $type === ComponentType::Core => $this->coreUrl($driver, $update),
            $update->source === PendingUpdate::SOURCE_WORDPRESS_ORG => $this->wordpressOrgUrl($type, $update),
            default => $this->transientUrl($driver, $type, $update),
        };

        return PackageSource::create($type, $update->slug, $update->newVersion, $url, $this->guard);
    }

    private function wordpressOrgUrl(ComponentType $type, PendingUpdate $update): string
    {
        $slug = $update->wporgSlug ?? $update->slug;
        if (preg_match(self::WPORG_SLUG, $slug) !== 1) {
            throw ArtifactException::noPackage($update->slug, $update->newVersion, "l'slug de WordPress.org no és vàlid.");
        }
        $pattern = $type === ComponentType::Theme ? $this->settings->wordpressOrgThemeUrl : $this->settings->wordpressOrgPluginUrl;

        return sprintf($pattern, rawurlencode($slug), rawurlencode($update->newVersion));
    }

    /** The release package of the site's locale (WPLANG), en_US when it has none or it is not valid. */
    private function coreUrl(SiteDriver $driver, PendingUpdate $update): string
    {
        $warnings = [];
        $result = $this->reader->run($driver, ['option', 'get', 'WPLANG'], $this->settings->commandTimeoutSeconds, 'la llengua del lloc', $warnings);
        $locale = $result !== null && $result->successful() ? ($this->reader->lastLine($result->stdout) ?? '') : '';

        return $locale === '' || $locale === 'en_US' || preg_match(self::LOCALE, $locale) !== 1
            ? sprintf($this->settings->wordpressOrgCoreUrl, rawurlencode($update->newVersion))
            : sprintf($this->settings->wordpressOrgLocalizedCoreUrl, $locale, rawurlencode($update->newVersion));
    }

    private function transientUrl(SiteDriver $driver, ComponentType $type, PendingUpdate $update): string
    {
        $name = $type === ComponentType::Theme ? 'update_themes' : 'update_plugins';
        $warnings = [];
        $result = $this->reader->run($driver, ['transient', 'get', $name, '--network', '--format=json'], $this->settings->commandTimeoutSeconds, "el transient {$name}", $warnings);
        $data = $result !== null && $result->successful() ? $this->reader->json($result->stdout) : null;
        if ($data === null) {
            throw ArtifactException::noPackage($update->slug, $update->newVersion, $warnings[0] ?? "no s'han pogut llegir les dades d'actualització del lloc.");
        }

        $kind = $type === ComponentType::Theme ? ComponentType::Theme : ComponentType::Plugin;
        foreach (is_array($data['response'] ?? null) ? $data['response'] : [] as $key => $entry) {
            if (PendingUpdates::slugOf($kind, (string) $key) !== $update->slug || ! is_array($entry)) {
                continue;
            }
            $package = $entry['package'] ?? null;
            if (is_string($package) && $package !== '') {
                $this->guard->register($package, $update->slug, $update->newVersion);
            }
            if (($entry['new_version'] ?? null) !== $update->newVersion) {
                throw ArtifactException::noPackage($update->slug, $update->newVersion, 'el lloc ara ofereix una altra versió: cal tornar a planificar.');
            }
            if (! is_string($package) || $package === '') {
                throw ArtifactException::noPackage($update->slug, $update->newVersion, 'el lloc no té URL de descàrrega (llicència no registrada?).');
            }

            return $package;
        }

        throw ArtifactException::noPackage($update->slug, $update->newVersion, "el lloc ja no té aquesta actualització al transient {$name}.");
    }
}
