<?php

declare(strict_types=1);

use App\Enums\ScheduledWindowStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The scheduler's record of each maintenance window occurrence of a site
 * (ADR-0006, UZEROWPUP-129): one row per site and opening, so a site is
 * handled once per window (never retried in the same one), what it ended in
 * (updated, failed, skipped with its reason, deferred to the next window with
 * priority) and the daily summary's source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_windows', static function (Blueprint $table): void {
            $table->id();
            // Sites are deactivated, never deleted: history must not cascade.
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->timestampTz('opens_at');
            $table->timestampTz('closes_at');
            $table->text('status')->default(ScheduledWindowStatus::Pending->value);
            // Deferred from its previous window: goes before the others (after security).
            $table->boolean('priority')->default(false);
            $table->foreignId('run_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('reason')->nullable();
            // Estimate, security, majors waiting for staging… (operator-facing, no secrets).
            $table->jsonb('details')->default('{}');
            $table->timestampsTz();

            $table->unique(['site_id', 'opens_at']);
            $table->index('status');
            $table->index('updated_at');
        });
        EnumCheck::add('scheduled_windows', 'status', ScheduledWindowStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_windows');
    }
};
