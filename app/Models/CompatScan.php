<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $sha256
 * @property string $php_minor
 * @property string $rules_version
 * @property int $error_count
 * @property int $warning_count
 * @property array<mixed> $summary
 * @property CarbonImmutable $created_at
 */
class CompatScan extends Model
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
            'error_count' => 'integer',
            'warning_count' => 'integer',
            'summary' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
