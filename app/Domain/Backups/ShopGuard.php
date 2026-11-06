<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Enums\ComponentType;
use App\Models\Site;
use App\Models\SiteSnapshot;

/**
 * Whether restoring a site's database may lose a shop's orders, customers or
 * users (AGENTS.md rule 4, UZEROWPUP-46). Conservative: a site whose
 * inventory is unknown counts as a shop. Its database is only restored with
 * --accept-data-loss and the operator typing the site's name (never --force).
 * The restore run compares the copy's order and user watermark with the site
 * (NewWritesDetector, UZEROWPUP-58) and tells which orders and users would be
 * lost. A manual restore always asks for the confirmation; only the rollback
 * of an update puts the database back by itself, and only when nothing was
 * written (DatabaseRollbackStep, UZEROWPUP-59).
 */
final class ShopGuard
{
    /**
     * WooCommerce installed, active or not; true when there is no snapshot.
     */
    public static function isShop(?SiteSnapshot $snapshot): bool
    {
        if ($snapshot === null) {
            return true;
        }
        foreach ($snapshot->plugins as $plugin) {
            if (is_array($plugin) && (($plugin['slug'] ?? null) === 'woocommerce' || ($plugin['type'] ?? null) === ComponentType::Woocommerce->value)) {
                return true;
            }
        }

        return false;
    }

    public static function latest(Site $site): ?SiteSnapshot
    {
        return SiteSnapshot::query()->where('site_id', $site->id)->orderByDesc('taken_at')->orderByDesc('id')->first();
    }

    /**
     * @param  NewWrites|null  $writes  null when the site was not read (the CLI checks before any run)
     */
    public static function message(string $copyDate, ?NewWrites $writes = null): string
    {
        return "El lloc és (o pot ser) una botiga: restaurar-ne la base de dades esborra les comandes, els clients i els usuaris entrats des de la còpia ({$copyDate}). "
            .($writes?->describe() ?? 'Sense connectar-se al lloc no es pot saber quins són.');
    }
}
