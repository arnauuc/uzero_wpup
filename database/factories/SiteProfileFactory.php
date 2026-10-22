<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteProfile>
 */
class SiteProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'verify_urls' => ['/'],
            'mask_selectors' => [],
            'frozen_components' => [],
            'excluded_components' => [],
            'notification_channels' => [],
        ];
    }
}
