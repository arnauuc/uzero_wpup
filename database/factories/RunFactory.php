<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuditChannel;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Run;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Run>
 */
class RunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'actor' => 'sistema (scheduler)',
            'channel' => AuditChannel::System,
            'type' => RunType::Update,
            'status' => RunStatus::Queued,
            'flags' => [],
        ];
    }
}
