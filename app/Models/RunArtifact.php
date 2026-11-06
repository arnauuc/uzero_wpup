<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ComponentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $run_id
 * @property ComponentType $component_type
 * @property string $slug
 * @property string $version
 * @property string $sha256
 * @property int $size_bytes
 * @property string $path relative to wpup.artifacts.dir
 * @property string|null $source_host never the URL
 * @property CarbonImmutable|null $purged_at
 * @property CarbonImmutable $created_at
 */
class RunArtifact extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'component_type' => ComponentType::class,
            'size_bytes' => 'integer',
            'purged_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
