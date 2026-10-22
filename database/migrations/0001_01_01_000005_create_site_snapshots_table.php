<?php

declare(strict_types=1);

use App\Enums\PhpVersionSource;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Runs after the run tables because snapshots reference runs.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('run_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('core_version');
            $table->string('php_version');
            $table->text('php_source');
            $table->string('db_server_version');
            $table->jsonb('plugins')->default('[]');
            $table->jsonb('themes')->default('[]');
            $table->jsonb('dropins')->default('[]');
            $table->jsonb('mu_plugins')->default('[]');
            $table->jsonb('pending_updates')->default('[]');
            // WooCommerce status, cron, maintenance mode, free disk space.
            $table->jsonb('environment')->default('{}');
            $table->timestampTz('taken_at');
            $table->timestampsTz();
        });
        DB::statement('CREATE INDEX site_snapshots_site_taken_desc ON site_snapshots (site_id, taken_at DESC)');
        EnumCheck::add('site_snapshots', 'php_source', PhpVersionSource::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('site_snapshots');
    }
};
