<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SiteDriverType;
use Database\Factories\HostingAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $hosting_server_id
 * @property string $system_user
 * @property SiteDriverType $driver
 * @property string|null $credential_ref
 * @property string|null $notes
 */
class HostingAccount extends Model
{
    /** @use HasFactory<HostingAccountFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => SiteDriverType::class,
        ];
    }

    /**
     * @return BelongsTo<HostingServer, $this>
     */
    public function hostingServer(): BelongsTo
    {
        return $this->belongsTo(HostingServer::class);
    }

    /**
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
