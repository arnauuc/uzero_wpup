<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/**
 * What the temporary HTTP probe reported (UZEROWPUP-30). $version is null when
 * the probe could not be read; $error says why. $removed is false when the
 * probe file may still be on the site (it expires and deletes itself anyway).
 */
final readonly class HttpProbeResult
{
    /**
     * @param  list<string>  $extensions
     */
    public function __construct(
        public string $fileName,
        public ?string $version,
        public ?string $sapi = null,
        public array $extensions = [],
        public ?string $error = null,
        public bool $removed = true,
    ) {}

    /**
     * "8.3" from "8.3.14", or null.
     */
    public function minor(): ?string
    {
        return $this->version === null ? null : implode('.', array_slice(explode('.', $this->version), 0, 2));
    }
}
