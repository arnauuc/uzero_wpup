<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Every site keeps its restore points on its own server (UZEROWPUP-40,
 * ADR-0009): one shared site_local destination, linked to every existing site.
 * New sites are linked when they are created (SiteLocalDestination).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $id = DB::table('backup_targets')->where('type', 'site_local')->orderBy('id')->value('id');
            if ($id === null) {
                $now = now();
                $id = DB::table('backup_targets')->insertGetId([
                    'name' => 'local',
                    'type' => 'site_local',
                    'config' => '{}',
                    'retention' => '{}',
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::statement(
                'INSERT INTO site_backup_targets (site_id, backup_target_id, created_at, updated_at)
                 SELECT id, ?, now(), now() FROM sites
                 ON CONFLICT (site_id, backup_target_id) DO NOTHING',
                [$id],
            );
        });
    }

    public function down(): void
    {
        // Links and the row stay: restore points may refer to them (backups.backup_target_id).
    }
};
