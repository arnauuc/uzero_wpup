<?php

declare(strict_types=1);

use App\Enums\AuditChannel;
use App\Enums\BackupKind;
use App\Enums\BackupTargetType;
use App\Enums\ChangeResult;
use App\Enums\CheckResult;
use App\Enums\ComponentType;
use App\Enums\RunStatus;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Enums\SkipReason;
use App\Enums\UpdateStrategy;
use App\Enums\VerificationPhase;
use App\Enums\Viewport;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table): void {
            $table->id();
            // Null for fleet-level runs. Sites are deactivated, never deleted: history must not cascade.
            $table->foreignId('site_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('parent_run_id')->nullable()->constrained('runs')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('actor');
            $table->text('channel');
            $table->text('type');
            $table->text('strategy')->nullable();
            $table->text('status')->default(RunStatus::Queued->value);
            // allow_major, skip_quarantine, dry_run, now…
            $table->jsonb('flags')->default('{}');
            $table->jsonb('result_summary')->nullable();
            $table->timestampTz('queued_at')->useCurrent();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('deadline_at')->nullable();
            // ADR-0009: operator acknowledgement that protects a restore point.
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['site_id', 'status']);
            $table->index('status');
            $table->index('type');
        });
        DB::statement('CREATE INDEX runs_created_at_desc ON runs (created_at DESC)');
        EnumCheck::add('runs', 'channel', AuditChannel::class);
        EnumCheck::add('runs', 'type', RunType::class);
        EnumCheck::add('runs', 'strategy', UpdateStrategy::class);
        EnumCheck::add('runs', 'status', RunStatus::class);

        Schema::create('run_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->text('name');
            $table->text('status')->default(RunStepStatus::Pending->value);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('summary')->nullable();
            // Full output, already passed through the secret redactor.
            $table->text('output')->nullable();
            $table->timestampTz('output_purged_at')->nullable();
            $table->timestampsTz();

            $table->unique(['run_id', 'sequence']);
        });
        EnumCheck::add('run_steps', 'status', RunStepStatus::class);

        Schema::create('run_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->text('component_type');
            $table->string('slug');
            $table->string('version_before')->nullable();
            $table->string('version_after')->nullable();
            $table->text('result');
            $table->text('reason')->nullable();
            $table->text('reason_detail')->nullable();
            $table->boolean('is_major')->default(false);
            $table->timestampsTz();

            $table->index('run_id');
        });
        EnumCheck::add('run_changes', 'component_type', ComponentType::class);
        EnumCheck::add('run_changes', 'result', ChangeResult::class);
        EnumCheck::add('run_changes', 'reason', SkipReason::class);

        Schema::create('backup_targets', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->text('type');
            // Non-secret settings only. Secrets (the "encrypted config") live in the
            // SecretStore (ADR-0002) and are referenced by credential_ref.
            $table->jsonb('config')->default('{}');
            $table->uuid('credential_ref')->nullable();
            $table->jsonb('retention')->default('{}');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });
        EnumCheck::add('backup_targets', 'type', BackupTargetType::class);

        Schema::create('backups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('run_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('backup_target_id')->constrained()->restrictOnDelete();
            $table->text('kind');
            $table->text('path');
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->boolean('protected')->default(false);
            $table->jsonb('order_watermark')->nullable();
            // Soft: records that the file was purged by retention.
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement('CREATE INDEX backups_site_created_desc ON backups (site_id, created_at DESC)');
        EnumCheck::add('backups', 'kind', BackupKind::class);

        Schema::create('verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->text('phase');
            $table->text('url');
            $table->text('viewport');
            $table->decimal('diff_percent', 6, 3)->nullable();
            $table->decimal('threshold', 5, 2)->nullable();
            $table->jsonb('screenshots')->default('{}');
            $table->jsonb('console_errors')->default('[]');
            $table->jsonb('http_errors')->default('[]');
            $table->jsonb('php_errors')->default('[]');
            $table->text('result');
            $table->timestampTz('images_purged_at')->nullable();
            $table->timestampsTz();

            $table->index('run_id');
        });
        EnumCheck::add('verifications', 'phase', VerificationPhase::class);
        EnumCheck::add('verifications', 'viewport', Viewport::class);
        EnumCheck::add('verifications', 'result', CheckResult::class);

        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->unique()->constrained()->cascadeOnDelete();
            $table->jsonb('summary')->default('{}');
            $table->text('html_path')->nullable();
            $table->text('pdf_path')->nullable();
            $table->jsonb('deliveries')->default('[]');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
        Schema::dropIfExists('verifications');
        Schema::dropIfExists('backups');
        Schema::dropIfExists('backup_targets');
        Schema::dropIfExists('run_changes');
        Schema::dropIfExists('run_steps');
        Schema::dropIfExists('runs');
    }
};
