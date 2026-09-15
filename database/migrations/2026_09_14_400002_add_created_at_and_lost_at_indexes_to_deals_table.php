<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the hot deal filters and sorts (plan section 8, step 12
 * quality pass).
 *
 * - created_at: ListDeals sorts by it by default.
 * - lost_at: revenue lost in a period, the win/loss report's lost branch and
 *   the board's lost column filter on it (won_at already has its own index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->index('created_at', 'deals_created_at_index');
            $table->index('lost_at', 'deals_lost_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropIndex('deals_lost_at_index');
            $table->dropIndex('deals_created_at_index');
        });
    }
};
