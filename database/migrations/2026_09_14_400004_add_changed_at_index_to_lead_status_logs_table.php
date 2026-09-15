<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index on lead_status_logs.changed_at (plan section 8, decision A-5).
 *
 * The conversion funnel report reads every status change inside a period
 * across all leads; the only index on the column was the composite
 * (lead_id, changed_at), which cannot serve a range without a lead. The
 * deal_stage_logs table already carries the equivalent single-column index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_status_logs', function (Blueprint $table): void {
            $table->index('changed_at', 'lead_status_logs_changed_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('lead_status_logs', function (Blueprint $table): void {
            $table->dropIndex('lead_status_logs_changed_at_index');
        });
    }
};
