<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the hot lead filters and sorts (plan section 8, step 12
 * quality pass).
 *
 * - created_at: ListLeads sorts by it by default; the dashboard's new-leads
 *   figure and the lead, source-performance and conversion-funnel reports
 *   filter their period on it.
 * - qualified_at: LeadFunnelMetrics counts leads qualified in a period.
 *
 * No existing index starts with either column, so every one of those queries
 * scanned the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->index('created_at', 'leads_created_at_index');
            $table->index('qualified_at', 'leads_qualified_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex('leads_qualified_at_index');
            $table->dropIndex('leads_created_at_index');
        });
    }
};
