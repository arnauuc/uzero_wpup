<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChangeResult;
use App\Enums\ComponentType;
use App\Models\Run;
use App\Models\RunChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RunChange>
 */
class RunChangeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'component_type' => ComponentType::Plugin,
            'slug' => fake()->slug(2),
            'version_before' => '1.0.0',
            'version_after' => '1.0.1',
            'result' => ChangeResult::Updated,
        ];
    }
}
