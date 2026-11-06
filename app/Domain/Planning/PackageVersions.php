<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Inventory\PendingUpdate;
use App\Enums\ComponentType;
use App\Models\PackageVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The first sighting of each offered version (UZEROWPUP-127, ADR-0005 section 3).
 * Every inventory records the versions its pending updates offer; the quarantine
 * counts from the earliest of the first sighting and the WordPress.org date (both
 * are upper bounds of the real release, so the earliest one is the closest).
 *
 * Recording is idempotent and never moves a date forward: a later sighting or a
 * later WordPress.org date (its `last_updated` also moves on readme edits) keeps
 * the earlier one. Keys are the installed slug and the offered version string as
 * the site reports them.
 */
final readonly class PackageVersions
{
    /** Size of the string columns. */
    private const int COLUMN_LENGTH = 255;

    /**
     * @param  list<PendingUpdate>  $updates
     */
    public function record(array $updates, CarbonImmutable $seenAt): void
    {
        $rows = [];
        foreach ($updates as $update) {
            $type = ComponentType::tryFrom($update->type);
            if ($type === null) {
                continue;
            }
            $released = $update->releasedAt === null ? null : CarbonImmutable::parse($update->releasedAt);
            $key = self::key($type, $update->slug, $update->newVersion);
            $rows[$key] = [
                'component_type' => $type->value,
                'slug' => self::column($update->slug),
                'version' => self::column($update->newVersion),
                'first_seen_at' => $seenAt->toIso8601String(),
                'released_at' => $released?->toIso8601String(),
            ];
        }
        if ($rows === []) {
            return;
        }

        PackageVersion::query()->upsert(array_values($rows), ['component_type', 'slug', 'version'], [
            'first_seen_at' => DB::raw('LEAST(package_versions.first_seen_at, excluded.first_seen_at)'),
            'released_at' => DB::raw('LEAST(package_versions.released_at, excluded.released_at)'),
        ]);
    }

    /**
     * Since when each version is known to exist, by key(); versions never recorded are left out.
     *
     * @param  list<PlannedChange>  $changes
     * @return array<string, CarbonImmutable>
     */
    public function knownSince(array $changes): array
    {
        if ($changes === []) {
            return [];
        }
        $slugs = array_values(array_unique(array_map(static fn (PlannedChange $c): string => self::column($c->slug), $changes)));

        $since = [];
        foreach (PackageVersion::query()->whereIn('slug', $slugs)->get() as $row) {
            $date = $row->released_at === null || $row->first_seen_at->lessThan($row->released_at) ? $row->first_seen_at : $row->released_at;
            $since[self::key($row->component_type, $row->slug, $row->version)] = $date;
        }

        return $since;
    }

    public static function key(ComponentType $type, string $slug, string $version): string
    {
        return $type->value."\0".self::column($slug)."\0".self::column($version);
    }

    /** Fits a site-provided value in a varchar(255) column (valid UTF-8, at most 255 bytes). */
    private static function column(string $value): string
    {
        return mb_strcut(mb_scrub($value, 'UTF-8'), 0, self::COLUMN_LENGTH, 'UTF-8');
    }
}
