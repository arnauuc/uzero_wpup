<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Inventory\PendingUpdate;
use App\Models\PackageVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The first sighting of each offered version (UZEROWPUP-127, ADR-0005 section 3).
 * Every inventory records the versions its pending updates offer; the quarantine
 * counts from the earliest of the first sighting and the WordPress.org date (both
 * are upper bounds of the real release, so the earliest one is the closest).
 *
 * Recording is idempotent and never moves a date forward: a later sighting or a
 * later WordPress.org date (its `last_updated` also moves on readme edits) keeps
 * the earlier one. Rows are keyed by PackageVersionKey (type, installed slug,
 * source, version). last_seen_at moves forward on every sighting; purge() drops
 * the rows no inventory has offered for `retention_days` (scheduled by UZEROWPUP-134).
 */
final readonly class PackageVersions
{
    public function __construct(private int $retentionDays) {}

    /**
     * @param  list<PendingUpdate>  $updates
     */
    public function record(array $updates, CarbonImmutable $seenAt): void
    {
        $rows = [];
        foreach ($updates as $update) {
            $key = PackageVersionKey::ofUpdate($update);
            if ($key === null) {
                continue;
            }
            $released = $update->releasedAt === null ? null : CarbonImmutable::parse($update->releasedAt);
            $rows[$key->id()] = [
                'component_type' => $key->type->value,
                'slug' => $key->slug,
                'source' => $key->source,
                'version' => $key->version,
                'first_seen_at' => $seenAt->toIso8601String(),
                'last_seen_at' => $seenAt->toIso8601String(),
                'released_at' => $released?->toIso8601String(),
            ];
        }
        if ($rows === []) {
            return;
        }

        PackageVersion::query()->upsert(array_values($rows), ['component_type', 'slug', 'source', 'version'], [
            'first_seen_at' => DB::raw('LEAST(package_versions.first_seen_at, excluded.first_seen_at)'),
            'last_seen_at' => DB::raw('GREATEST(package_versions.last_seen_at, excluded.last_seen_at)'),
            'released_at' => DB::raw('LEAST(package_versions.released_at, excluded.released_at)'),
        ]);
    }

    /**
     * Since when each of these versions is known to exist, by PackageVersionKey::id();
     * versions never recorded are left out. Only the given rows are read.
     *
     * @param  list<PackageVersionKey>  $keys
     * @return array<string, CarbonImmutable>
     */
    public function knownSince(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $rows = PackageVersion::query()->where(static function (Builder $query) use ($keys): void {
            foreach ($keys as $key) {
                $query->orWhere(static fn (Builder $q): Builder => $q->where([
                    'component_type' => $key->type->value, 'slug' => $key->slug, 'source' => $key->source, 'version' => $key->version,
                ]));
            }
        })->get();

        $since = [];
        foreach ($rows as $row) {
            $date = $row->released_at === null || $row->first_seen_at->lessThan($row->released_at) ? $row->first_seen_at : $row->released_at;
            $since[(new PackageVersionKey($row->component_type, $row->slug, $row->source, $row->version))->id()] = $date;
        }

        return $since;
    }

    /**
     * Drops the versions that no inventory has offered for `retention_days`; the
     * number of rows removed. A version offered again later starts a new clock,
     * which only makes its quarantine longer.
     */
    public function purge(CarbonImmutable $now): int
    {
        $deleted = PackageVersion::query()->where('last_seen_at', '<', $now->subDays($this->retentionDays))->delete();

        return is_int($deleted) ? $deleted : 0;
    }
}
