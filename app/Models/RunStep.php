<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RunStepKind;
use App\Enums\RunStepStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RunStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $run_id
 * @property int $sequence
 * @property string $name
 * @property RunStepKind $kind
 * @property RunStepStatus $status
 * @property int $attempts
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $duration_ms
 * @property string|null $summary
 * @property string|null $output
 * @property CarbonImmutable|null $output_purged_at
 * @property int|null $timeout_seconds
 * @property string|null $error
 * @property array<string, mixed>|null $result
 */
class RunStep extends Model
{
    /** @use HasFactory<RunStepFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'kind' => RunStepKind::class,
            'status' => RunStepStatus::class,
            'attempts' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'duration_ms' => 'integer',
            'output_purged_at' => 'immutable_datetime',
            'timeout_seconds' => 'integer',
            'result' => 'array',
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
