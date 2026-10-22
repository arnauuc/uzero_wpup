<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HostingPanel;
use App\Models\HostingServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HostingServer>
 */
class HostingServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord().'-srv',
            'hostname' => fake()->unique()->domainName(),
            'ssh_port' => 22,
            'panel' => HostingPanel::Plesk,
        ];
    }
}
