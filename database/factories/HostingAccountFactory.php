<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SiteDriverType;
use App\Models\HostingAccount;
use App\Models\HostingServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HostingAccount>
 */
class HostingAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hosting_server_id' => HostingServer::factory(),
            'system_user' => fake()->unique()->userName(),
            'driver' => SiteDriverType::Ssh,
        ];
    }
}
