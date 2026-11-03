<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sealed envelope (ADR-0011, format v2): the data key is sealed with the X25519
 * public key, which needs no separate nonce. Format v1 rows cannot be opened any
 * more; they only ever existed in development and test databases, so the
 * migration refuses to run while any is left (`migrate:fresh` once).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('secrets')->exists()) {
            throw new RuntimeException('The secrets table still holds format v1 rows, which ADR-0011 no longer reads. They only exist in development: run `php artisan migrate:fresh` (it erases the development database).');
        }

        Schema::table('secrets', function (Blueprint $table): void {
            $table->dropColumn('dek_nonce');
        });
    }

    public function down(): void
    {
        Schema::table('secrets', function (Blueprint $table): void {
            $table->text('dek_nonce')->default('');
        });
    }
};
