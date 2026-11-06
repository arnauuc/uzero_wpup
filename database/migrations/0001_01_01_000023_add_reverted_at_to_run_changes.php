<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * When a rollback undid a change of the run (UZEROWPUP-57): the row keeps its
 * versions before and after, and its result becomes `reverted`. Null for the
 * changes never undone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('run_changes', static function (Blueprint $table): void {
            $table->timestampTz('reverted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('run_changes', static function (Blueprint $table): void {
            $table->dropColumn('reverted_at');
        });
    }
};
