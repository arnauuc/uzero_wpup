<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PhpVersionSource;
use Carbon\CarbonImmutable;
use Database\Factories\SiteSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property int|null $run_id
 * @property string $core_version
 * @property string $php_version
 * @property PhpVersionSource $php_source
 * @property string $db_server_version
 * @property array<int, mixed> $plugins
 * @property array<int, mixed> $themes
 * @property array<int, mixed> $dropins
 * @property array<int, mixed> $mu_plugins
 * @property array<int, mixed> $pending_updates
 * @property array<string, mixed> $environment
 * @property CarbonImmutable $taken_at
 */
class SiteSnapshot extends Model
{
    /** @use HasFactory<SiteSnapshotFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'php_source' => PhpVersionSource::class,
            'taken_at' => 'immutable_datetime',
            'plugins' => 'array',
            'themes' => 'array',
            'dropins' => 'array',
            'mu_plugins' => 'array',
            'pending_updates' => 'array',
            'environment' => 'array',
        ];
    }

    /**
     * The site's latest snapshot whose PHP the HTTP probe confirmed, by taken_at;
     * only one newer than $maxAgeSeconds when given.
     */
    public static function latestWebVerifiedPhp(Site $site, ?int $maxAgeSeconds = null): ?self
    {
        return self::query()
            ->where('site_id', $site->id)
            ->where('php_source', PhpVersionSource::HttpProbe)
            ->when($maxAgeSeconds !== null, static fn ($query) => $query->where('taken_at', '>=', now()->subSeconds($maxAgeSeconds ?? 0)))
            ->latest('taken_at')
            ->first();
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
