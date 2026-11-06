<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cache of the static compatibility analysis (UZEROWPUP-50): the result for a
 * package (SHA-256) analysed for a PHP minor with a given set of rules (the
 * PHPCompatibility and PHP_CodeSniffer versions and the ignore patterns). Only
 * counts and a capped, redacted summary; never the package nor its code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compat_scans', static function (Blueprint $table): void {
            $table->id();
            $table->char('sha256', 64);
            $table->string('php_minor', 16);
            $table->string('rules_version', 100);
            $table->unsignedInteger('error_count');
            $table->unsignedInteger('warning_count');
            // [{file, line, message, severity}], capped.
            $table->jsonb('summary');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['sha256', 'php_minor', 'rules_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compat_scans');
    }
};
