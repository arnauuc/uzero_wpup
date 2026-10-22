<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChangeResult;
use App\Enums\ComponentType;
use App\Enums\SkipReason;
use Database\Factories\RunChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $run_id
 * @property ComponentType $component_type
 * @property string $slug
 * @property string|null $version_before
 * @property string|null $version_after
 * @property ChangeResult $result
 * @property SkipReason|null $reason
 * @property string|null $reason_detail
 * @property bool $is_major
 */
class RunChange extends Model
{
    /** @use HasFactory<RunChangeFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'component_type' => ComponentType::class,
            'result' => ChangeResult::class,
            'reason' => SkipReason::class,
            'is_major' => 'boolean',
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
