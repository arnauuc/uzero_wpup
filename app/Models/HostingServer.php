<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HostingPanel;
use Carbon\CarbonImmutable;
use Database\Factories\HostingServerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $hostname
 * @property int $ssh_port
 * @property HostingPanel $panel
 * @property string|null $pinned_host_key
 * @property CarbonImmutable|null $pinned_host_key_at
 * @property string|null $notes
 */
class HostingServer extends Model
{
    /** @use HasFactory<HostingServerFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ssh_port' => 'integer',
            'panel' => HostingPanel::class,
            'pinned_host_key_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<HostingAccount, $this>
     */
    public function hostingAccounts(): HasMany
    {
        return $this->hasMany(HostingAccount::class);
    }
}
