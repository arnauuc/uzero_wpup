<?php

declare(strict_types=1);

namespace App\Domain\Sites;

use App\Enums\UpdateStrategy;

/**
 * Fields of a site and its profile as given by the caller. A null value means
 * "not given" (left untouched on edit, defaulted on add); list fields replace
 * the stored list when given. No credentials ever travel here.
 */
final readonly class SiteInput
{
    /**
     * @param  list<string>|null  $tags
     * @param  list<int>|null  $windowDays
     * @param  list<string>|null  $verifyUrls
     * @param  list<string>|null  $maskSelectors
     * @param  list<string>|null  $frozen
     * @param  list<string>|null  $excluded
     * @param  list<string>|null  $notify
     */
    public function __construct(
        public ?string $url = null,
        public ?string $wpPath = null,
        public ?string $phpBinary = null,
        public ?array $tags = null,
        public ?UpdateStrategy $strategy = null,
        public ?array $windowDays = null,
        public ?string $windowStart = null,
        public ?string $windowEnd = null,
        public ?bool $autoUpdate = null,
        public ?bool $active = null,
        public ?array $verifyUrls = null,
        public ?array $maskSelectors = null,
        public ?string $diffThreshold = null,
        public ?string $checkoutProduct = null,
        public ?array $frozen = null,
        public ?array $excluded = null,
        public ?array $notify = null,
        public ?bool $includeUploads = null,
        public ?string $externalBackup = null,
    ) {}
}
