<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A site blocked for AUTOMATIC updates only (ADR-0006 §3, UZEROWPUP-129):
 * after consecutive failed windows the scheduler stops updating it until an
 * operator reviews it and turns automatic updates on again
 * (`site:edit --auto-update`). Manual updates are not affected: that is
 * blocked_reason's job. auto_update_unblocked_at marks the operator's review:
 * only the windows settled after it count towards the next block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', static function (Blueprint $table): void {
            $table->timestampTz('auto_update_blocked_at')->nullable();
            $table->text('auto_update_blocked_reason')->nullable();
            $table->timestampTz('auto_update_unblocked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', static function (Blueprint $table): void {
            $table->dropColumn(['auto_update_blocked_at', 'auto_update_blocked_reason', 'auto_update_unblocked_at']);
        });
    }
};
