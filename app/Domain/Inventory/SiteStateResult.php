<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/**
 * Pre-update state of a site (UZEROWPUP-33). A null field means it could not be
 * read (there is a matching warning) or, for woocommerce, that the plugin is
 * not installed and active.
 */
final readonly class SiteStateResult
{
    /**
     * @param  list<string>  $warnings  operator-facing
     */
    public function __construct(
        public ?WooCommerceState $woocommerce,
        public ?CronState $cron,
        public ?bool $maintenance,
        public ?int $diskFreeBytes,
        public array $warnings,
    ) {}

    /**
     * @return array{woocommerce: ?array<string, mixed>, cron: ?array<string, mixed>, maintenance: ?bool, disk_free_bytes: ?int, warnings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'woocommerce' => $this->woocommerce?->toArray(),
            'cron' => $this->cron?->toArray(),
            'maintenance' => $this->maintenance,
            'disk_free_bytes' => $this->diskFreeBytes,
            'warnings' => $this->warnings,
        ];
    }
}
