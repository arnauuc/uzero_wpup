<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BackupTargetType;
use App\Models\BackupTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupTarget>
 */
class BackupTargetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'type' => BackupTargetType::SiteLocal,
            'config' => [],
            'retention' => ['days' => 7],
            'active' => true,
        ];
    }
}
