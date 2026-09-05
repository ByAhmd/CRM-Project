<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead status history (decision D-7): one append-only row per transition,
 * carrying the qualification note. Feeds the timeline and the qualification
 * history of a lead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_status_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('from_status_id')->nullable()->constrained('lead_statuses')->restrictOnDelete();
            $table->foreignId('to_status_id')->constrained('lead_statuses')->restrictOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->text('notes')->nullable();

            $table->index(['lead_id', 'changed_at'], 'lead_status_logs_lead_id_changed_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_status_logs');
    }
};
