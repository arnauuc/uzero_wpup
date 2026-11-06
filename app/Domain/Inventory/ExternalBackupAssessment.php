<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use Carbon\CarbonImmutable;

final readonly class ExternalBackupAssessment
{
    /**
     * @param  list<string>  $warnings  empty when the site has a known, recent enough external backup
     * @param  string|null  $declaredType  plesk, dropbox, plugin, other, none; null = not declared
     */
    public function __construct(
        public array $warnings,
        public ?string $declaredType,
        public ?CarbonImmutable $lastBackupAt,
    ) {}

    public function ok(): bool
    {
        return $this->warnings === [];
    }
}
