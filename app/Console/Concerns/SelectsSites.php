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
    /** Inactive sites named explicitly and left out by the last activeSelectedSites() call. */
    private int $skippedSites = 0;

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
     * The selected sites that are active. An inactive site (only possible when named
     * explicitly) is not run: its warning goes to STDERR so --json stays parseable, and
     * it is counted in $skippedSites so the command does not exit 0.
     *
     * @param  string  $skipMessage  what happens to an inactive site, e.g. "no es planifica"
     * @return list<Site>|null null when the selection is invalid or nothing active is left
     */
    protected function activeSelectedSites(string $skipMessage): ?array
    {
        $sites = $this->selectedSites();
        if ($sites === null) {
            return null;
        }

        $this->skippedSites = 0;
        foreach ($sites->reject(static fn (Site $site): bool => $site->active) as $inactive) {
            $this->getOutput()->getErrorStyle()->warning("{$inactive->name}: inactiu, {$skipMessage}.");
            $this->skippedSites++;
        }
        $active = array_values($sites->filter(static fn (Site $site): bool => $site->active)->all());

        return $active === [] ? null : $active;
    }

    /**
     * The exit code once the runs are done: a skipped site is never a clean success.
     */
    protected function exitCodeWithSkipped(int $code): int
    {
        return $code === 0 && $this->skippedSites > 0 ? self::FAILURE : $code;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, static fn (mixed $v): bool => is_string($v) && $v !== '')) : [];
    }
}
