<?php

declare(strict_types=1);

use App\Enums\RunType;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Key rotation and secrets:rekey run on the workers (ADR-0011, UZEROWPUP-138).
 * A key rotation targets a hosting account, not a site. Nullable: existing rows
 * stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->foreignId('hosting_account_id')->nullable()->after('site_id')->constrained()->restrictOnDelete();
        });

        // Rebuilt again on purpose: a database migrated with 000011 before the
        // key_rotation and secrets_rekey types existed does not allow them yet.
        DB::statement('ALTER TABLE "runs" DROP CONSTRAINT "runs_type_check"');
        EnumCheck::add('runs', 'type', RunType::class);
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('hosting_account_id');
        });

        DB::statement('ALTER TABLE "runs" DROP CONSTRAINT "runs_type_check"');
        DB::statement(<<<'SQL'
            ALTER TABLE "runs" ADD CONSTRAINT "runs_type_check"
            CHECK ("type" IN ('inventory', 'plan', 'update', 'backup', 'restore', 'rollback', 'verify', 'cleanup', 'site_test'))
            SQL);
    }
};
