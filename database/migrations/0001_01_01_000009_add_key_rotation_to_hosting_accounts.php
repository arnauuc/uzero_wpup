<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * SSH key management (UZEROWPUP-123): the public half of the account's key
 * (not secret, shown again on demand), the key being rotated in and the old
 * key still to be removed from the server, so an interrupted rotation resumes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hosting_accounts', function (Blueprint $table): void {
            $table->text('ssh_public_key')->nullable();
            $table->uuid('pending_credential_ref')->nullable();
            $table->uuid('retiring_credential_ref')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hosting_accounts', function (Blueprint $table): void {
            $table->dropColumn(['ssh_public_key', 'pending_credential_ref', 'retiring_credential_ref']);
        });
    }
};
