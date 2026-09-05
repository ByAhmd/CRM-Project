<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Query indexes for the audit ledger (decision A-5).
 *
 * spatie's stock migration indexes only log_name and the two morph pairs. The
 * audit screen and every record timeline filter by subject / causer AND order
 * by created_at, and the retention prune scans by created_at, so each of those
 * paths gets a covering index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('activitylog.table_name'), function (Blueprint $table): void {
            $table->index(['subject_type', 'subject_id', 'created_at'], 'activity_log_subject_created_at_index');
            $table->index(['causer_type', 'causer_id', 'created_at'], 'activity_log_causer_created_at_index');
            $table->index(['log_name', 'created_at'], 'activity_log_log_name_created_at_index');
            $table->index('event', 'activity_log_event_index');
            $table->index('created_at', 'activity_log_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table(config('activitylog.table_name'), function (Blueprint $table): void {
            $table->dropIndex('activity_log_subject_created_at_index');
            $table->dropIndex('activity_log_causer_created_at_index');
            $table->dropIndex('activity_log_log_name_created_at_index');
            $table->dropIndex('activity_log_event_index');
            $table->dropIndex('activity_log_created_at_index');
        });
    }
};
