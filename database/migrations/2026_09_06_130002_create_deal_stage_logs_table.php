<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deal stage history (decision D-8): one append-only row per stage change,
 * carrying the note and the seconds the deal spent in the previous stage
 * (changed_at minus the previous row's changed_at, or minus deals.created_at
 * for the first row). Feeds the timeline and the stage-duration reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_stage_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('from_stage_id')->nullable()->constrained('pipeline_stages')->restrictOnDelete();
            $table->foreignId('to_stage_id')->constrained('pipeline_stages')->restrictOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->text('notes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->index(['deal_id', 'changed_at'], 'deal_stage_logs_deal_id_changed_at_index');
            $table->index('changed_at', 'deal_stage_logs_changed_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_stage_logs');
    }
};
