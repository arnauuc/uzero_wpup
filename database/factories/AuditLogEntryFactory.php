<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuditChannel;
use App\Enums\AuditResult;
use App\Models\AuditLogEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLogEntry>
 */
class AuditLogEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'occurred_at' => now(),
            'actor' => 'sistema (scheduler)',
            'channel' => AuditChannel::System,
            'action' => 'site.update',
            'result' => AuditResult::Success,
            'context' => [],
        ];
    }
}
