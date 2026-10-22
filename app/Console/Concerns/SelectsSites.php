<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Domain\Sites\SiteSelectionException;
use App\Domain\Sites\SiteSelector;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * For commands whose signature includes WpupCommand::TARGETS.
 */
trait SelectsSites
{
    /**
     * @return Collection<int, Site>|null null after printing why the selection is invalid
     */
    protected function selectedSites(): ?Collection
    {
        try {
            return app(SiteSelector::class)->select(
                self::stringList($this->argument('sites')),
                self::stringList($this->option('tag')),
                $this->option('all') === true,
            );
        } catch (SiteSelectionException $e) {
            $this->error($e->getMessage());

            return null;
        }
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, static fn (mixed $v): bool => is_string($v) && $v !== '')) : [];
    }
}
