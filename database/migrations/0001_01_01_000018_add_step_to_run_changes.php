<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which step of the run wrote a change (UZEROWPUP-48): a retried step replaces
 * only its own rows, never those of a later step. Null for older rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('run_changes', static function (Blueprint $table): void {
            $table->string('step')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('run_changes', static function (Blueprint $table): void {
            $table->dropColumn('step');
        });
    }
};
