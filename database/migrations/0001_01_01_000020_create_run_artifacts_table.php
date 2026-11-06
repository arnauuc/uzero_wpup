<?php

declare(strict_types=1);

use App\Enums\ComponentType;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Package artifacts of a run (UZEROWPUP-49): the exact bytes analysed and
 * installed, kept on the tool server with their SHA-256. Never the download
 * URL (it may carry a licence token): only its host. The row stays after
 * retention removes the file (purged_at), so the run still says what it installed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_artifacts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained()->restrictOnDelete();
            $table->text('component_type');
            $table->string('slug');
            $table->string('version');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            // Relative to wpup.artifacts.dir.
            $table->text('path');
            $table->string('source_host')->nullable();
            $table->timestampTz('purged_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['run_id', 'component_type', 'slug', 'version']);
            $table->index('created_at');
        });
        EnumCheck::add('run_artifacts', 'component_type', ComponentType::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('run_artifacts');
    }
};
