<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the task reached its current assignee (decision D-21).
 *
 * The task performance report measures the time to finish from this moment
 * to `completed_at`. TaskAssignmentStampObserver is the only writer: it
 * stamps the column when a task is created with an assignee or its assignee
 * changes, and clears it when the task is unassigned — on every path that
 * saves a task (the task form, RecordAssignmentService, recurrence, routine
 * tasks, imports, factories). DATETIME like the task's other moments
 * (DATABASE_DESIGN.md), indexed for reading by period.
 *
 * Nothing recorded the moment of a reassignment before D-21, so the rows
 * that already have an assignee are backfilled from `created_at` (the owner's
 * rule); unassigned rows stay null. down() drops the column and its index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dateTime('assigned_at')->nullable()->after('assigned_by');
            $table->index('assigned_at', 'tasks_assigned_at_index');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex('tasks_assigned_at_index');
            $table->dropColumn('assigned_at');
        });
    }

    /**
     * Existing assigned tasks count from their creation, soft-deleted rows
     * included; plain DML, so it also runs inside a test transaction.
     */
    public function backfill(): void
    {
        DB::table('tasks')
            ->whereNotNull('assignee_id')
            ->whereNull('assigned_at')
            ->update(['assigned_at' => DB::raw('created_at')]);
    }
};
