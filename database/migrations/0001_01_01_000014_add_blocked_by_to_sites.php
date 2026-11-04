<?php

declare(strict_types=1);

use App\Enums\SiteBlockSource;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Who blocked a site (UZEROWPUP-34): the prerequisites check or an operator.
 * blocked_reason and blocked_at already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', static function (Blueprint $table): void {
            $table->text('blocked_by')->nullable();
        });
        EnumCheck::add('sites', 'blocked_by', SiteBlockSource::class);
        // A block that existed before this column was set by hand.
        DB::table('sites')->whereNotNull('blocked_reason')->update(['blocked_by' => SiteBlockSource::Operator->value]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE "sites" DROP CONSTRAINT "sites_blocked_by_check"');
        Schema::table('sites', static function (Blueprint $table): void {
            $table->dropColumn('blocked_by');
        });
    }
};
