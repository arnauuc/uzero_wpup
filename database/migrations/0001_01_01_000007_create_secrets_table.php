<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envelope-encrypted secrets (ADR-0002). Each row holds the ciphertext, its data
 * key wrapped with the KEK and the KEK version. `credential_ref` columns elsewhere
 * point here. Nothing in this table is readable without the KEK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secrets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('purpose');
            $table->text('ciphertext');
            $table->text('nonce');
            $table->text('wrapped_dek');
            $table->text('dek_nonce');
            $table->unsignedSmallInteger('kek_version');
            $table->timestampsTz();

            $table->index('kek_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secrets');
    }
};
