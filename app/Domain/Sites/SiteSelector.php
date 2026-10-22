<?php

declare(strict_types=1);

namespace App\Domain\Sites;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves the target of a command (UZEROWPUP-24): named sites, --tag=… or
 * --all, exactly one of them. Tags and --all only select active sites; a site
 * named explicitly is returned even if inactive, and the command decides.
 */
final class SiteSelector
{
    /**
     * @param  list<string>  $names
     * @param  list<string>  $tags
     * @return Collection<int, Site>
     *
     * @throws SiteSelectionException
     */
    public function select(array $names, array $tags, bool $all): Collection
    {
        $modes = count(array_filter([$names !== [], $tags !== [], $all]));
        if ($modes === 0) {
            throw new SiteSelectionException('Indica un o més llocs, --tag=<etiqueta> o --all.');
        }
        if ($modes > 1) {
            throw new SiteSelectionException('Fes servir només una forma de seleccionar: noms de lloc, --tag o --all.');
        }

        if ($names !== []) {
            $sites = Site::query()->whereIn('name', $names)->orderBy('name')->get();
            $unknown = array_diff($names, $sites->map(static fn (Site $site): string => $site->name)->all());

            return $unknown === []
                ? $sites
                : throw new SiteSelectionException('Llocs desconeguts: '.implode(', ', $unknown).'.');
        }

        $sites = Site::query()
            ->where('active', true)
            ->when($tags !== [], static function (Builder $query) use ($tags): void {
                $query->where(static function (Builder $any) use ($tags): void {
                    foreach ($tags as $tag) {
                        $any->orWhereJsonContains('tags', $tag);
                    }
                });
            })
            ->orderBy('name')
            ->get();

        if ($sites->isEmpty()) {
            throw new SiteSelectionException($tags === []
                ? 'No hi ha cap lloc actiu.'
                : 'Cap lloc actiu té les etiquetes: '.implode(', ', $tags).'.');
        }

        return $sites;
    }
}
