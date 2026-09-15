<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite index deals (status, deleted_at, created_at) (plan section 8,
 * step 13 evidence).
 *
 * ListDeals opens on the status tab (`status = ?`), every list query carries
 * the soft-delete filter `deleted_at IS NULL`, and the default sort is
 * `created_at desc, id desc`. With the two equalities leading and created_at
 * last, a tab page is read in index order and stops after the page instead
 * of looking up every deal of the status through deals_status_index and
 * filesorting them. deals_status_index stays for the status-only aggregates
 * (dashboard, reports, board columns).
 *
 * A plain secondary index: MySQL 8 and MariaDB (App\Support\Database\
 * DatabaseEngine, A-21) spell it identically, so there is no engine branch
 * (verified with migrate and rollback on MySQL 8.4 and MariaDB 10.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->index(['status', 'deleted_at', 'created_at'], 'deals_status_deleted_at_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropIndex('deals_status_deleted_at_created_at_index');
        });
    }
};
