<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BackupTargetType;
use Database\Factories\BackupTargetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property BackupTargetType $type
 * @property array<string, mixed> $config
 * @property string|null $credential_ref
 * @property array<string, mixed> $retention
 * @property bool $active
 */
class BackupTarget extends Model
{
    /** @use HasFactory<BackupTargetFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BackupTargetType::class,
            'config' => 'array',
            'retention' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Site, $this>
     */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_backup_targets')->withTimestamps();
    }

    /**
     * @return HasMany<Backup, $this>
     */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }
}
