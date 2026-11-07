<?php

declare(strict_types=1);

use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationKind;
use App\Enums\NotificationSeverity;
use App\Enums\NotificationThreshold;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Notifications (UZEROWPUP-79):
 *
 * - notification_destinations: where a channel sends (a Telegram chat, a Slack
 *   webhook, a mailbox…). Managed by an admin from the CLI only (ADR-0008).
 *   The token or webhook lives in the SecretStore (credential_ref, ADR-0002);
 *   `address` only holds what is not secret. `channel` is a key of
 *   wpup.notifications.channels, so a new channel needs no migration.
 * - notification_subscriptions: which destinations each operator follows and
 *   from which severity on.
 * - notification_deliveries: one row per notification sent to a destination,
 *   with the masked destination and the redacted error. No message content.
 *   A run's rows are part of its report.
 * - site_profiles.notification_min_severity: the site's own minimum severity;
 *   site_profiles.notification_channels (already there) limits the channels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_destinations', static function (Blueprint $table): void {
            $table->id();
            $table->text('name')->unique();
            $table->text('channel');
            $table->text('address')->nullable();
            $table->text('credential_ref')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('notification_subscriptions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_destination_id')->constrained()->cascadeOnDelete();
            $table->text('min_severity')->default(NotificationThreshold::All->value);
            $table->timestampsTz();

            $table->unique(['user_id', 'notification_destination_id']);
        });
        EnumCheck::add('notification_subscriptions', 'min_severity', NotificationThreshold::class);

        Schema::create('notification_deliveries', static function (Blueprint $table): void {
            $table->id();
            $table->text('kind');
            $table->text('severity');
            $table->foreignId('run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('notification_destination_id')->nullable()->constrained()->nullOnDelete();
            $table->text('channel');
            $table->text('destination');
            $table->text('status');
            $table->text('error')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('run_id');
            $table->index('created_at');
        });
        EnumCheck::add('notification_deliveries', 'kind', NotificationKind::class);
        EnumCheck::add('notification_deliveries', 'severity', NotificationSeverity::class);
        EnumCheck::add('notification_deliveries', 'status', NotificationDeliveryStatus::class);

        Schema::table('site_profiles', static function (Blueprint $table): void {
            $table->text('notification_min_severity')->default(NotificationThreshold::All->value);
        });
        EnumCheck::add('site_profiles', 'notification_min_severity', NotificationThreshold::class);
    }

    public function down(): void
    {
        Schema::table('site_profiles', static function (Blueprint $table): void {
            $table->dropColumn('notification_min_severity');
        });
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_subscriptions');
        Schema::dropIfExists('notification_destinations');
    }
};
