<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UpdateStrategy;
use App\Models\HostingAccount;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hosting_account_id' => HostingAccount::factory(),
            'name' => fake()->unique()->domainWord(),
            'url' => fake()->url(),
            'tags' => [],
            'wp_path' => '/var/www/html',
            'strategy' => UpdateStrategy::InPlace,
        ];
    }
}
