<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A site can store its restore points in one or several destinations
 * (UZEROWPUP-37, ADR-0009).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_backup_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_target_id')->constrained()->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['site_id', 'backup_target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_backup_targets');
    }
};
