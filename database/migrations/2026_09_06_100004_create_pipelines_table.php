<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales pipelines (decisions D-8, A-4).
 *
 * A pipeline is the ordered set of stages a deal moves through. Exactly one
 * pipeline is the default for new deals — the flag lives here and
 * PipelineService keeps it unique; the default can be neither deactivated
 * nor deleted. Pipelines are soft-deleted because deals keep referencing
 * them (deals.pipeline_id RESTRICT) and their history must stay explainable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('name_ar', 'pipelines_name_ar_unique');
            $table->unique('name_en', 'pipelines_name_en_unique');
            $table->index('is_default', 'pipelines_is_default_index');
            $table->index(['is_active', 'sort'], 'pipelines_is_active_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipelines');
    }
};
