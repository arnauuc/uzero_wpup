<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Inventory\PendingUpdate;
use App\Enums\ComponentType;

/**
 * Which package a version belongs to (UZEROWPUP-127): the installed slug is not
 * enough, two unrelated premium plugins may share a folder name. The source is
 * the WordPress.org slug for WordPress.org packages and the package host for the
 * others (premium packages served from the vendor's licence server).
 */
final readonly class PackageVersionKey
{
    /** Size of the string columns. */
    private const int COLUMN_LENGTH = 255;

    public string $slug;

    public string $source;

    public string $version;

    public function __construct(public ComponentType $type, string $slug, string $source, string $version)
    {
        $this->slug = self::column($slug);
        $this->source = self::column($source);
        $this->version = self::column($version);
    }

    /**
     * From the parts of a pending update as stored in the snapshot (see PendingUpdate).
     */
    public static function of(ComponentType $type, string $slug, string $version, ?string $source, ?string $wporgSlug, ?string $packageHost): self
    {
        $origin = match (true) {
            $type === ComponentType::Core => 'wordpress.org:wordpress',
            $source === PendingUpdate::SOURCE_WORDPRESS_ORG => 'wordpress.org:'.($wporgSlug ?? $slug),
            default => 'host:'.($packageHost ?? 'unknown'),
        };

        return new self($type, $slug, $origin, $version);
    }

    public static function ofUpdate(PendingUpdate $update): ?self
    {
        $type = ComponentType::tryFrom($update->type);

        return $type === null ? null : self::of($type, $update->slug, $update->newVersion, $update->source, $update->wporgSlug, $update->packageHost);
    }

    public function withVersion(string $version): self
    {
        return new self($this->type, $this->slug, $this->source, $version);
    }

    public function id(): string
    {
        return implode("\0", [$this->type->value, $this->slug, $this->source, $this->version]);
    }

    /** Fits a site-provided value in a varchar(255) column (valid UTF-8, at most 255 bytes). */
    private static function column(string $value): string
    {
        return mb_strcut(mb_scrub($value, 'UTF-8'), 0, self::COLUMN_LENGTH, 'UTF-8');
    }
}
