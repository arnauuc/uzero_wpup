<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Every time a run lost its worker and the engine picked it up again
 * (UZEROWPUP-62): when, in which phase, at which step and what the engine
 * decided (RunInterruption). The operator is always told about them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', static function (Blueprint $table): void {
            $table->jsonb('interruptions')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('runs', static function (Blueprint $table): void {
            $table->dropColumn('interruptions');
        });
    }
};
