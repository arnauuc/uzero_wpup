<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PhpVersionSource;
use App\Models\Site;
use App\Models\SiteSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteSnapshot>
 */
class SiteSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'core_version' => '6.8.1',
            'php_version' => '8.3.10',
            'php_source' => PhpVersionSource::PleskHandler,
            'db_server_version' => '11.4.2-MariaDB',
            'taken_at' => now(),
        ];
    }
}
