<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Maintenance mode around the update (UZEROWPUP-53), per site: true = on,
 * false = off, null = the default of wpup.updates.maintenance.default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_profiles', static function (Blueprint $table): void {
            $table->boolean('maintenance_mode')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_profiles', static function (Blueprint $table): void {
            $table->dropColumn('maintenance_mode');
        });
    }
};
