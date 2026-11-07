<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BackupTargetType;
use App\Enums\SiteBlockSource;
use App\Enums\UpdateStrategy;
use Carbon\CarbonImmutable;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
 * @property CarbonImmutable|null $auto_update_blocked_at
 * @property string|null $auto_update_blocked_reason
 * @property CarbonImmutable|null $auto_update_unblocked_at
 * @property bool $use_maintenance_mode
 * @property bool $active
 * @property string|null $blocked_reason
 * @property CarbonImmutable|null $blocked_at
 * @property SiteBlockSource|null $blocked_by
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
            'auto_update_blocked_at' => 'immutable_datetime',
            'auto_update_unblocked_at' => 'immutable_datetime',
            'use_maintenance_mode' => 'boolean',
            'active' => 'boolean',
            'blocked_at' => 'immutable_datetime',
            'blocked_by' => SiteBlockSource::class,
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
     * Destinations this site's restore points are stored in (UZEROWPUP-37).
     *
     * @return BelongsToMany<BackupTarget, $this>
     */
    public function backupTargets(): BelongsToMany
    {
        return $this->belongsToMany(BackupTarget::class, 'site_backup_targets')->withTimestamps();
    }

    /**
     * The active destinations other than the site's own server, which every
     * restore point is also copied to (UZEROWPUP-43).
     *
     * @return BelongsToMany<BackupTarget, $this>
     */
    public function externalBackupTargets(): BelongsToMany
    {
        return $this->backupTargets()
            ->where('backup_targets.type', '!=', BackupTargetType::SiteLocal->value)
            ->where('backup_targets.active', true)
            ->orderBy('backup_targets.id');
    }

    /**
     * @return HasMany<Backup, $this>
     */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }
}
