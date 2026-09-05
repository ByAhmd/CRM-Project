<?php

declare(strict_types=1);

use App\Enums\BadgeColor;
use App\Enums\StageKind;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pipeline stages (decisions D-8, A-4).
 *
 * Stages are rows the administrator names, colours and orders inside a
 * pipeline; `kind` is the behaviour the deal workflow reasons about
 * (CHECK-constrained to StageKind) and `probability` feeds the forecast
 * (0–100, CHECK-constrained; Won is always 100 and Lost always 0). Every
 * pipeline carries at least one Open stage, exactly one Won, exactly one
 * Lost and exactly one default stage — invariants owned by PipelineService.
 * A stage never changes pipeline (PipelineStageObserver). No soft deletes:
 * a stage referenced by a deal is protected by the RESTRICT foreign key on
 * deals.stage_id, and the pipeline itself is RESTRICT so it can only be
 * soft-deleted while its stages exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('pipelines')->restrictOnDelete();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->string('kind', 32);
            $table->unsignedTinyInteger('probability')->default(0);
            $table->string('color', 20)->default(BadgeColor::Primary->value);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['pipeline_id', 'name_ar'], 'pipeline_stages_pipeline_id_name_ar_unique');
            $table->unique(['pipeline_id', 'name_en'], 'pipeline_stages_pipeline_id_name_en_unique');
            $table->index(['pipeline_id', 'sort'], 'pipeline_stages_pipeline_id_sort_index');
            $table->index(['pipeline_id', 'kind'], 'pipeline_stages_pipeline_id_kind_index');
        });

        EnumCheck::apply('pipeline_stages', 'kind', StageKind::class);
        EnumCheck::apply('pipeline_stages', 'color', BadgeColor::class);

        DB::statement('ALTER TABLE `pipeline_stages` ADD CONSTRAINT `pipeline_stages_probability_check` CHECK (`probability` BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        if (Schema::hasTable('pipeline_stages')) {
            EnumCheck::drop('pipeline_stages', 'color');
            EnumCheck::drop('pipeline_stages', 'kind');
        }

        // The probability CHECK is dropped together with the table.
        Schema::dropIfExists('pipeline_stages');
    }
};
