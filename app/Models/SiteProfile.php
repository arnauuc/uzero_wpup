<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SiteProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property array<int, mixed> $verify_urls
 * @property array<int, mixed> $mask_selectors
 * @property string $diff_threshold
 * @property array<string, mixed>|null $checkout_product
 * @property array<int, mixed> $frozen_components
 * @property array<int, mixed> $excluded_components
 * @property array<int, mixed> $notification_channels
 * @property bool $include_uploads
 * @property array<string, mixed>|null $external_backup
 */
class SiteProfile extends Model
{
    /** @use HasFactory<SiteProfileFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verify_urls' => 'array',
            'mask_selectors' => 'array',
            'checkout_product' => 'array',
            'frozen_components' => 'array',
            'excluded_components' => 'array',
            'notification_channels' => 'array',
            'external_backup' => 'array',
            'diff_threshold' => 'decimal:2',
            'include_uploads' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
