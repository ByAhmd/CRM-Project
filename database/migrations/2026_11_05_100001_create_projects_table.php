<?php

declare(strict_types=1);

use App\Enums\BadgeColor;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects (decision D-30): the partitions of the customer base. A project
 * («المشروع») is a business line or brand with its own leads, contacts,
 * accounts and deals; the four tables carry a required `project_id`.
 *
 * Configurable business data, so bilingual rows like every lookup (A-4):
 * a name in both languages (unique in each), a badge colour from the shared
 * BadgeColor set (CHECK through EnumCheck), active / inactive, a single
 * default — the project new users start in and existing records were
 * assigned to — and an order for the switcher and the cards. The records
 * reference a project with RESTRICT foreign keys, so a project in use can
 * only be deactivated, never removed; the table therefore carries no soft
 * deletes. Exactly one row is the default at all times (ProjectService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->string('color', 20)->default(BadgeColor::Gray->value);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique('name_ar', 'projects_name_ar_unique');
            $table->unique('name_en', 'projects_name_en_unique');
            $table->index(['is_active', 'sort'], 'projects_is_active_sort_index');
            $table->index('is_default', 'projects_is_default_index');
        });

        EnumCheck::apply('projects', 'color', BadgeColor::class);
    }

    public function down(): void
    {
        if (Schema::hasTable('projects')) {
            EnumCheck::drop('projects', 'color');
        }

        Schema::dropIfExists('projects');
    }
};
