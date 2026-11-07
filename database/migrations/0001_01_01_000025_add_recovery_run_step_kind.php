<?php

declare(strict_types=1);

use App\Enums\RunStepKind;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Recovery steps (UZEROWPUP-60): the update run rolls itself back with forward
 * steps of its own (files, database, maintenance) instead of compensations.
 * Only widens the CHECK constraint; existing rows stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE "run_steps" DROP CONSTRAINT "run_steps_kind_check"');
        EnumCheck::add('run_steps', 'kind', RunStepKind::class);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE "run_steps" DROP CONSTRAINT "run_steps_kind_check"');
        DB::statement("ALTER TABLE \"run_steps\" ADD CONSTRAINT \"run_steps_kind_check\" CHECK (\"kind\" IN ('forward', 'compensation'))");
    }
};
