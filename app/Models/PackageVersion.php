<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ComponentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * First sighting of a version of a component (UZEROWPUP-127, ADR-0005 section 3).
 * Written only through PackageVersions::record().
 *
 * @property int $id
 * @property ComponentType $component_type
 * @property string $slug
 * @property string $source see PackageVersionKey
 * @property string $version
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $released_at from WordPress.org, an upper bound of the real release
 */
class PackageVersion extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'component_type' => ComponentType::class,
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }
}
