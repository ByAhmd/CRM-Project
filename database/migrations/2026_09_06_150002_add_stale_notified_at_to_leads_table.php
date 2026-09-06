<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the owner was last told the lead went stale (plan section 3.6).
 *
 * Stamped by LeadStaleService so the daily pass never repeats the notice,
 * and cleared by ActivityRecorder whenever a new activity moves
 * last_activity_at forward, which re-arms the lead for a later notice.
 * Bookkeeping, not a workflow column: written quietly, never audited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->timestamp('stale_notified_at')->nullable()->after('last_activity_at');

            $table->index('stale_notified_at', 'leads_stale_notified_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex('leads_stale_notified_at_index');
            $table->dropColumn('stale_notified_at');
        });
    }
};
