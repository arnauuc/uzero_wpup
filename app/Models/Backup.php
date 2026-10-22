<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BackupKind;
use Carbon\CarbonImmutable;
use Database\Factories\BackupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property int|null $run_id
 * @property int $backup_target_id
 * @property BackupKind $kind
 * @property string $path
 * @property int $size_bytes
 * @property string $sha256
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $expires_at
 * @property bool $protected
 * @property array<string, mixed>|null $order_watermark
 * @property CarbonImmutable|null $deleted_at
 * @property CarbonImmutable $created_at
 */
class Backup extends Model
{
    /** @use HasFactory<BackupFactory> */
    use HasFactory;

    protected $table = 'backups';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => BackupKind::class,
            'size_bytes' => 'integer',
            'verified_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'protected' => 'boolean',
            'order_watermark' => 'array',
            'deleted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
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

    /**
     * @return BelongsTo<BackupTarget, $this>
     */
    public function backupTarget(): BelongsTo
    {
        return $this->belongsTo(BackupTarget::class);
    }
}
