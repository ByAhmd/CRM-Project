<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index on tasks.ends_at (plan section 8, decision D-12, step 13 evidence).
 *
 * CalendarFeed::tasks() reads the range as three bounded slices, one per
 * index: tasks starting inside the range (tasks_starts_at_index), due-only
 * tasks due inside it (tasks_due_at_index), and tasks that started before
 * the range and still run into it, found by `ends_at >= range start` — this
 * index. Before, the feed's OR over starts_at / ends_at / due_at scanned the
 * whole table for every range, empty months included.
 *
 * A plain secondary index: MySQL 8 and MariaDB (App\Support\Database\
 * DatabaseEngine, A-21) spell it identically, so there is no engine branch
 * (verified with migrate and rollback on MySQL 8.4 and MariaDB 10.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->index('ends_at', 'tasks_ends_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex('tasks_ends_at_index');
        });
    }
};
