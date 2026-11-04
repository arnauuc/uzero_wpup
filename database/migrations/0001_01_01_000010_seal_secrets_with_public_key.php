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
 * migration refuses to run while any is left.
 *
 * `kek_confirmations` records which public keys the scheduler has checked
 * against their private half (KekConfirmation); sealing is refused otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('secrets')->exists()) {
            throw new RuntimeException('The secrets table still holds format v1 rows, which ADR-0011 no longer reads. Stop and convert them with a one-off procedure; never reset the database to get past this (audit_log is never purged).');
        }

        Schema::table('secrets', function (Blueprint $table): void {
            $table->dropColumn('dek_nonce');
        });

        Schema::create('kek_confirmations', function (Blueprint $table): void {
            $table->unsignedInteger('version')->primary();
            $table->char('public_key_sha256', 64);
            $table->timestampTz('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kek_confirmations');

        Schema::table('secrets', function (Blueprint $table): void {
            $table->text('dek_nonce')->default('');
        });
    }
};
