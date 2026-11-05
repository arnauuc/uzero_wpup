<?php

declare(strict_types=1);

use App\Enums\RunType;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The tool's own backup runs on the workers (UZEROWPUP-47): a new run type.
 * Only widens the CHECK constraint; existing rows stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE "runs" DROP CONSTRAINT "runs_type_check"');
        EnumCheck::add('runs', 'type', RunType::class);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE "runs" DROP CONSTRAINT "runs_type_check"');
        DB::statement(<<<'SQL'
            ALTER TABLE "runs" ADD CONSTRAINT "runs_type_check"
            CHECK ("type" IN ('inventory', 'plan', 'update', 'backup', 'restore', 'rollback', 'verify', 'cleanup', 'site_test', 'key_rotation', 'secrets_rekey'))
            SQL);
    }
};
