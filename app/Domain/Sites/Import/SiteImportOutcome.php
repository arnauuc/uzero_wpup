<?php

declare(strict_types=1);

namespace App\Domain\Sites\Import;

final readonly class SiteImportOutcome
{
    /**
     * @param  list<string>  $fields  Columns that change (update) or file fields given (create).
     */
    public function __construct(
        public string $name,
        public ImportAction $action,
        public array $fields,
        /** Why the scheduler blocked the site for automatic updates; the import never lifts it. */
        public ?string $autoUpdateBlocked = null,
    ) {}
}
