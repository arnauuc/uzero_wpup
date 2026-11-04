<?php

declare(strict_types=1);

use App\Enums\PhpVersionSource;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Real PHP detection (UZEROWPUP-29): a snapshot may record the CLI PHP when the
 * domain's handler could not be read. Only widens the CHECK constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE "site_snapshots" DROP CONSTRAINT "site_snapshots_php_source_check"');
        EnumCheck::add('site_snapshots', 'php_source', PhpVersionSource::class);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE "site_snapshots" DROP CONSTRAINT "site_snapshots_php_source_check"');
        DB::statement(<<<'SQL'
            ALTER TABLE "site_snapshots" ADD CONSTRAINT "site_snapshots_php_source_check"
            CHECK ("php_source" IN ('plesk_handler', 'http_probe', 'configured'))
            SQL);
    }
};
