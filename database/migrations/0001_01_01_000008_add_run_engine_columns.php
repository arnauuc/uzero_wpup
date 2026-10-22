<?php

declare(strict_types=1);

use App\Enums\RunStatus;
use App\Enums\RunStepKind;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Run engine (UZEROWPUP-21): the new "rolling_back" run status, and per-step
 * kind (forward or compensation), applied timeout, error and structured result.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE "runs" DROP CONSTRAINT "runs_status_check"');
        EnumCheck::add('runs', 'status', RunStatus::class);

        Schema::table('run_steps', function (Blueprint $table): void {
            $table->text('kind')->default(RunStepKind::Forward->value);
            $table->unsignedInteger('timeout_seconds')->nullable();
            // Short, redacted reason of a failure (timeout, exception, attempts exhausted…).
            $table->text('error')->nullable();
            // Redacted data handed to later steps and to the compensation.
            $table->jsonb('result')->nullable();
        });
        EnumCheck::add('run_steps', 'kind', RunStepKind::class);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE "run_steps" DROP CONSTRAINT "run_steps_kind_check"');

        Schema::table('run_steps', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'timeout_seconds', 'error', 'result']);
        });

        DB::statement('ALTER TABLE "runs" DROP CONSTRAINT "runs_status_check"');
        DB::statement("ALTER TABLE \"runs\" ADD CONSTRAINT \"runs_status_check\" CHECK (\"status\" IN ('queued', 'running', 'succeeded', 'failed', 'rolled_back', 'interrupted', 'cancelled', 'skipped'))");
    }
};
