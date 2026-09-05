<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead sources (decisions D-7, A-4).
 *
 * Where a lead came from (website, referral, campaign…) is configurable
 * business data, so it lives in bilingual rows rather than a code enum.
 * Leads reference a source with a RESTRICT foreign key, so a source in use
 * can only be deactivated, never removed; the table therefore carries no
 * soft deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique('name_ar', 'lead_sources_name_ar_unique');
            $table->unique('name_en', 'lead_sources_name_en_unique');
            $table->index(['is_active', 'sort'], 'lead_sources_is_active_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_sources');
    }
};
