<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite index leads (deleted_at, lead_status_id) (plan section 8, step 13
 * evidence).
 *
 * Every lead list query carries the soft-delete filter `deleted_at IS NULL`
 * and the status tabs of ListLeads add `lead_status_id IN (<status ids>)`.
 * The pagination count of those pages — `count(*) where lead_status_id in
 * (...) and deleted_at is null`, and `where deleted_at is null` on the all
 * tab — scanned the whole table; this index answers both from the index
 * alone. The page itself (`order by created_at desc, id desc limit 25`) is
 * read backwards on leads_created_at_index and stops after the page.
 *
 * Measured on MySQL 8.4 with 17 600 leads (step-13 closing check): a
 * (deleted_at, created_at) index, as first proposed, made the optimizer
 * treat `deleted_at IS NULL` as selective and read every lead row through
 * it for the status-tab counts (38 ms against 13 ms for the table scan);
 * (deleted_at, lead_status_id) serves those counts as a covering range scan
 * (5 ms) and leaves the page plan on leads_created_at_index. Leading with
 * deleted_at keeps leads_lead_status_id_foreign as the foreign key's index,
 * so the index can be dropped again without touching the key.
 *
 * A plain secondary index: MySQL 8 and MariaDB (App\Support\Database\
 * DatabaseEngine, A-21) spell it identically, so there is no engine branch
 * (verified with migrate and rollback on MySQL 8.4 and MariaDB 10.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->index(['deleted_at', 'lead_status_id'], 'leads_deleted_at_lead_status_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex('leads_deleted_at_lead_status_id_index');
        });
    }
};
