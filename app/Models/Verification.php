<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CheckResult;
use App\Enums\VerificationPhase;
use App\Enums\Viewport;
use Carbon\CarbonImmutable;
use Database\Factories\VerificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $run_id
 * @property VerificationPhase $phase
 * @property string $url
 * @property Viewport $viewport
 * @property string|null $diff_percent
 * @property string|null $threshold
 * @property array<string, mixed> $screenshots
 * @property array<int, mixed> $console_errors
 * @property array<int, mixed> $http_errors
 * @property array<int, mixed> $php_errors
 * @property CheckResult $result
 * @property CarbonImmutable|null $images_purged_at
 */
class Verification extends Model
{
    /** @use HasFactory<VerificationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phase' => VerificationPhase::class,
            'viewport' => Viewport::class,
            'diff_percent' => 'decimal:3',
            'threshold' => 'decimal:2',
            'screenshots' => 'array',
            'console_errors' => 'array',
            'http_errors' => 'array',
            'php_errors' => 'array',
            'result' => CheckResult::class,
            'images_purged_at' => 'immutable_datetime',
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
