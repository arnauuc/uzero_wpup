<?php

declare(strict_types=1);

use App\Enums\AuditChannel;
use App\Enums\AuditResult;
use App\Enums\HostingPanel;
use App\Enums\SiteDriverType;
use App\Enums\UpdateStrategy;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosting_servers', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('hostname');
            $table->unsignedInteger('ssh_port')->default(22);
            $table->text('panel');
            $table->text('pinned_host_key')->nullable();
            $table->timestampTz('pinned_host_key_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });
        EnumCheck::add('hosting_servers', 'panel', HostingPanel::class);

        Schema::create('hosting_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hosting_server_id')->constrained()->restrictOnDelete();
            $table->string('system_user');
            $table->text('driver');
            // Reference into the SecretStore (UZEROWPUP-15); never secret material.
            $table->uuid('credential_ref')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['hosting_server_id', 'system_user']);
        });
        EnumCheck::add('hosting_accounts', 'driver', SiteDriverType::class);

        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hosting_account_id')->constrained()->restrictOnDelete();
            $table->string('name')->unique();
            $table->string('url');
            $table->jsonb('tags')->default('[]');
            $table->string('wp_path');
            $table->string('php_binary')->nullable();
            $table->text('strategy')->default(UpdateStrategy::InPlace->value);
            // {"days":[1..7],"start":"01:00","end":"06:00"}; null = default window from config/wpup.php.
            $table->jsonb('maintenance_window')->nullable();
            $table->boolean('auto_update')->default(false);
            $table->boolean('use_maintenance_mode')->default(false);
            $table->boolean('active')->default(true);
            $table->text('blocked_reason')->nullable();
            $table->timestampTz('blocked_at')->nullable();
            $table->timestampsTz();
        });
        EnumCheck::add('sites', 'strategy', UpdateStrategy::class);
        DB::statement('CREATE INDEX sites_tags_gin ON sites USING GIN (tags)');

        Schema::create('site_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->jsonb('verify_urls')->default('[]');
            $table->jsonb('mask_selectors')->default('[]');
            $table->decimal('diff_threshold', 5, 2)->default('0.50');
            $table->jsonb('checkout_product')->nullable();
            $table->jsonb('frozen_components')->default('[]');
            $table->jsonb('excluded_components')->default('[]');
            $table->jsonb('notification_channels')->default('[]');
            $table->boolean('include_uploads')->default(false);
            // ADR-0009: {"type": plesk|dropbox|plugin|other|none, "notes": "..."}
            $table->jsonb('external_backup')->nullable();
            $table->timestampsTz();
        });

        Schema::create('audit_log', function (Blueprint $table): void {
            $table->id();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('actor');
            $table->text('channel');
            $table->text('action');
            $table->text('object_type')->nullable();
            $table->text('object_id')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->text('result');
            $table->jsonb('context')->default('{}');

            $table->index('occurred_at');
            $table->index(['object_type', 'object_id']);
            $table->index('user_id');
        });
        EnumCheck::add('audit_log', 'channel', AuditChannel::class);
        EnumCheck::add('audit_log', 'result', AuditResult::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('site_profiles');
        Schema::dropIfExists('sites');
        Schema::dropIfExists('hosting_accounts');
        Schema::dropIfExists('hosting_servers');
    }
};
