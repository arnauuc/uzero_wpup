<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

final readonly class ExternalBackupsResult
{
    /**
     * @param  list<ExternalBackupPlugin>  $plugins  ordered by slug
     * @param  list<string>  $warnings  redacted
     */
    public function __construct(public array $plugins, public array $warnings) {}

    /**
     * @return array{plugins: list<array{slug: string, name: string, active: bool, last_backup_at: string|null}>, warnings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'plugins' => array_map(static fn (ExternalBackupPlugin $p): array => $p->toArray(), $this->plugins),
            'warnings' => $this->warnings,
        ];
    }
}
