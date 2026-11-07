<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScheduledWindowStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One occurrence of a site's maintenance window as the scheduler handled it
 * (UZEROWPUP-129, ADR-0006).
 *
 * @property int $id
 * @property int $site_id
 * @property CarbonImmutable $opens_at
 * @property CarbonImmutable $closes_at
 * @property ScheduledWindowStatus $status
 * @property bool $priority
 * @property int|null $run_id
 * @property string|null $reason
 * @property array<string, mixed> $details
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class ScheduledWindow extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['details' => '{}', 'priority' => false, 'status' => 'pending'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opens_at' => 'immutable_datetime',
            'closes_at' => 'immutable_datetime',
            'status' => ScheduledWindowStatus::class,
            'priority' => 'boolean',
            'details' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
