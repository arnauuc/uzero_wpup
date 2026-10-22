<?php

declare(strict_types=1);

namespace App\Domain\Sites\Import;

final readonly class ParsedImport
{
    /**
     * @param  list<SiteImportEntry>  $entries
     * @param  list<string>  $errors
     */
    public function __construct(public array $entries, public array $errors) {}
}
