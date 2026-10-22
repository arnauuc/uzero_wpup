<?php

declare(strict_types=1);

namespace App\Domain\Sites\Import;

final readonly class SiteImportResult
{
    /**
     * @param  list<SiteImportOutcome>  $outcomes
     */
    public function __construct(public array $outcomes) {}

    public function count(ImportAction $action): int
    {
        return count(array_filter($this->outcomes, static fn (SiteImportOutcome $o): bool => $o->action === $action));
    }

    public function changes(): bool
    {
        return $this->count(ImportAction::Create) + $this->count(ImportAction::Update) > 0;
    }
}
