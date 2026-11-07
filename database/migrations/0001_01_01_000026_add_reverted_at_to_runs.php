<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * When a rollback started putting back the code of an update run (UZEROWPUP-61):
 * its own rollback or a manual one (`wpup rollback <run>`). The retention holds
 * the restore point of a reverted update until an operator reviews it
 * (ADR-0009), also when the update had succeeded. Null for updates never undone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', static function (Blueprint $table): void {
            $table->timestampTz('reverted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('runs', static function (Blueprint $table): void {
            $table->dropColumn('reverted_at');
        });
    }
};
