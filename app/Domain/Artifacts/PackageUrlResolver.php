<?php

declare(strict_types=1);

namespace App\Domain\Artifacts;

use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\PendingUpdate;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\ComponentType;

/**
 * The download URL of a pending update, read at download time and never
 * stored (the snapshot only keeps the host, PendingUpdates):
 *
 * - WordPress.org plugins and themes: downloads.wordpress.org, built from
 *   the WordPress.org slug and the version (config), not taken from the site;
 * - core: the package_url `wp core check-update` gives for that version (it
 *   is the site's locale);
 * - premium components: the `package` of the site's update_plugins or
 *   update_themes transient, where the site's registered licence put it.
 *
 * The site's output is never quoted in a message: it holds every package URL.
 */
final readonly class PackageUrlResolver
{
    public function __construct(private SecretRedactor $redactor, private ArtifactSettings $settings) {}

    /**
     * @throws ArtifactException
     */
    public function resolve(SiteDriver $driver, PendingUpdate $update): PackageSource
    {
        $type = ComponentType::tryFrom($update->type);
        $url = match (true) {
            $type === ComponentType::Core => $this->coreUrl($driver, $update),
            $type === null, ! in_array($type, [ComponentType::Plugin, ComponentType::Woocommerce, ComponentType::Theme], true) => throw ArtifactException::noPackage($update->slug, $update->newVersion, "tipus de component no admès «{$update->type}»."),
            $update->source === PendingUpdate::SOURCE_WORDPRESS_ORG => $this->wordpressOrgUrl($type, $update),
            default => $this->transientUrl($driver, $type, $update),
        };

        return PackageSource::create($type, $update->slug, $update->newVersion, $url, $this->redactor);
    }

    private function wordpressOrgUrl(ComponentType $type, PendingUpdate $update): string
    {
        $slug = $update->wporgSlug ?? $update->slug;
        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $slug) !== 1 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*$/D', $update->newVersion) !== 1) {
            throw ArtifactException::noPackage($update->slug, $update->newVersion, "l'slug o la versió de WordPress.org no són vàlids.");
        }
        $pattern = $type === ComponentType::Theme ? $this->settings->wordpressOrgThemeUrl : $this->settings->wordpressOrgPluginUrl;

        return sprintf($pattern, rawurlencode($slug), rawurlencode($update->newVersion));
    }

    private function coreUrl(SiteDriver $driver, PendingUpdate $update): string
    {
        foreach ($this->read($driver, ['core', 'check-update', '--format=json'], $update) as $row) {
            if (is_array($row) && ($row['version'] ?? null) === $update->newVersion && is_string($row['package_url'] ?? null) && $row['package_url'] !== '') {
                return $row['package_url'];
            }
        }

        throw ArtifactException::noPackage($update->slug, $update->newVersion, 'el lloc ja no ofereix aquesta versió del core.');
    }

    private function transientUrl(SiteDriver $driver, ComponentType $type, PendingUpdate $update): string
    {
        $name = $type === ComponentType::Theme ? 'update_themes' : 'update_plugins';
        $data = $this->read($driver, ['transient', 'get', $name, '--network', '--format=json'], $update);
        $response = is_array($data['response'] ?? null) ? $data['response'] : [];

        foreach ($response as $key => $entry) {
            $key = (string) $key;
            $slug = $type === ComponentType::Theme ? $key : (dirname($key) === '.' ? basename($key, '.php') : dirname($key));
            if ($slug !== $update->slug || ! is_array($entry)) {
                continue;
            }
            $package = $entry['package'] ?? null;
            if (is_string($package) && $package !== '') {
                PackageSource::guard($package, $this->redactor);
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

    /**
     * @param  list<string>  $args
     * @return array<mixed>
     */
    private function read(SiteDriver $driver, array $args, PendingUpdate $update): array
    {
        try {
            $result = $driver->wp($args, $this->settings->commandTimeoutSeconds);
        } catch (DriverException $e) {
            throw ArtifactException::noPackage($update->slug, $update->newVersion, $this->redactor->redact($e->getMessage()));
        }
        $data = $result->successful() ? json_decode($result->stdout, true) : null;
        if (! is_array($data)) {
            throw ArtifactException::noPackage($update->slug, $update->newVersion, "no s'han pogut llegir les dades d'actualització del lloc.");
        }

        return $data;
    }
}
