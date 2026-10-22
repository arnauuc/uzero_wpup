<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BackupKind;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Backup>
 */
class BackupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'backup_target_id' => BackupTarget::factory(),
            'kind' => BackupKind::Database,
            'path' => '/backups/'.fake()->uuid().'.sql.gz',
            'size_bytes' => fake()->numberBetween(1_000, 1_000_000),
            'sha256' => hash('sha256', fake()->uuid()),
        ];
    }
}
