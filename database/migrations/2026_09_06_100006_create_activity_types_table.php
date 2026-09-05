<?php

declare(strict_types=1);

use App\Enums\ActivityKind;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable activity types (decision A-4).
 *
 * Administrators name and order the types users pick when logging an
 * activity; `kind` is the behaviour code reasons about (direction, duration,
 * timeline rendering) and is constrained to ActivityKind at the database.
 * One system row per kind is seeded (is_system) and can never be deleted nor
 * change its kind. Activities reference a type with RESTRICT, so a type in
 * use cannot disappear; there are no soft deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->string('kind', 32);
            $table->string('icon', 50)->nullable();
            $table->string('color', 20);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique('name_ar', 'activity_types_name_ar_unique');
            $table->unique('name_en', 'activity_types_name_en_unique');
            $table->index('kind', 'activity_types_kind_index');
            $table->index(['is_active', 'sort'], 'activity_types_is_active_sort_index');
        });

        EnumCheck::apply('activity_types', 'kind', ActivityKind::class);
    }

    public function down(): void
    {
        if (Schema::hasTable('activity_types')) {
            EnumCheck::drop('activity_types', 'kind');
        }

        Schema::dropIfExists('activity_types');
    }
};
