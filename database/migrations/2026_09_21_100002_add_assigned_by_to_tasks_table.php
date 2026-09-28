<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who handed the task to its assignee (decision D-14).
 *
 * `assigned_by` is written by the trusted services only (TaskService on a
 * create for someone, RecordAssignmentService on every reassignment) and is
 * cleared when the task is unassigned, so TaskService::complete() can notify
 * the assigner — falling back to the creator — when the work is done. A
 * departed user's tasks keep working (nullOnDelete, the user-reference
 * default of CLAUDE.md section 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('assigned_by')->nullable()->after('assignee_id')->constrained('users')->nullOnDelete();
            $table->index('assigned_by', 'tasks_assigned_by_index');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropForeign(['assigned_by']);
            $table->dropIndex('tasks_assigned_by_index');
            $table->dropColumn('assigned_by');
        });
    }
};
