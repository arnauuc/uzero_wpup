<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CheckResult;
use App\Enums\VerificationPhase;
use App\Enums\Viewport;
use App\Models\Run;
use App\Models\Verification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Verification>
 */
class VerificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'phase' => VerificationPhase::Baseline,
            'url' => fake()->url(),
            'viewport' => Viewport::Desktop,
            'result' => CheckResult::Ok,
        ];
    }
}
