<?php

declare(strict_types=1);

namespace App\Domain\Sites\Import;

use App\Domain\Sites\SiteInput;
use App\Enums\HostingPanel;
use App\Enums\SiteDriverType;

/**
 * One site of an import file, already read into typed values. The placement is
 * only what the file says; the importer reconciles it with the stored servers.
 */
final readonly class SiteImportEntry
{
    /**
     * @param  list<string>  $clear  Canonical column names to reset (see SiteAdministration::CLEARABLE).
     * @param  list<string>  $fields  Names of the file fields that carry a value, for display.
     */
    public function __construct(
        public int $position,
        public string $name,
        public SiteInput $input,
        public array $clear,
        public array $fields,
        public ?string $server,
        public ?string $hostname,
        public ?int $sshPort,
        public ?HostingPanel $panel,
        public ?string $account,
        public ?SiteDriverType $driver,
    ) {}

    public function label(): string
    {
        return "Lloc {$this->position} («{$this->name}»)";
    }
}
