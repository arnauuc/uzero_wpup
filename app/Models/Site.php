<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UpdateStrategy;
use Carbon\CarbonImmutable;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $hosting_account_id
 * @property string $name
 * @property string $url
 * @property array<int, string> $tags
 * @property string $wp_path
 * @property string|null $php_binary
 * @property UpdateStrategy $strategy
 * @property array{days: list<int>, start: string, end: string}|null $maintenance_window
 * @property bool $auto_update
 * @property bool $use_maintenance_mode
 * @property bool $active
 * @property string|null $blocked_reason
 * @property CarbonImmutable|null $blocked_at
 */
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'strategy' => UpdateStrategy::class,
            'maintenance_window' => 'array',
            'auto_update' => 'boolean',
            'use_maintenance_mode' => 'boolean',
            'active' => 'boolean',
            'blocked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<HostingAccount, $this>
     */
    public function hostingAccount(): BelongsTo
    {
        return $this->belongsTo(HostingAccount::class);
    }

    /**
     * @return HasOne<SiteProfile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(SiteProfile::class);
    }

    /**
     * @return HasMany<SiteSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(SiteSnapshot::class);
    }

    /**
     * @return HasMany<Run, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * @return HasMany<Backup, $this>
     */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }
}
