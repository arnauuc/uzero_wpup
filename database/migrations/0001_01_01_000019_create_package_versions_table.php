<?php

declare(strict_types=1);

use App\Enums\ComponentType;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * First sighting of every offered version of every component (UZEROWPUP-127,
 * ADR-0005 section 3): the quarantine counts from it, or from the WordPress.org
 * date when there is one. Shared by the whole fleet: a version is the same bytes
 * on every site. Rows are never moved forward in time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_versions', function (Blueprint $table): void {
            $table->id();
            $table->text('component_type');
            $table->string('slug');
            $table->string('version');
            $table->timestampTz('first_seen_at');
            // From WordPress.org when it gives one; an upper bound of the real release.
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();

            $table->unique(['component_type', 'slug', 'version']);
        });
        EnumCheck::add('package_versions', 'component_type', ComponentType::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('package_versions');
    }
};
