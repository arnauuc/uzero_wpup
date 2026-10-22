<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RunStepStatus;
use App\Models\Run;
use App\Models\RunStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RunStep>
 */
class RunStepFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'sequence' => 1,
            'name' => 'inventory',
            'status' => RunStepStatus::Pending,
        ];
    }
}
