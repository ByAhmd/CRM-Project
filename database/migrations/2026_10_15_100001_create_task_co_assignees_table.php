<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The further assignees of a shared task (decision D-28): `tasks.assignee_id`
 * keeps the first assignee — the one the task is filed under — and each row
 * here names one more employee who holds the same task, with its one status,
 * checklist and thread.
 *
 * A user appears once per task (unique pair, which also serves "who shares
 * this task"); the `user_id` index answers "which tasks does this user share"
 * for My Work, the visibility scope (RecordVisibilityResolver, through the
 * SharesOwnership contract) and the reports. `assigned_by` and `assigned_at`
 * are the row's own D-14 / D-21 stamps: who added the employee and when the
 * task reached them, the start of their time to finish. DATETIME like every
 * other moment the design types. TaskService is the only writer; the lead
 * assignee is never also a row here (TaskCoAssigneeGuardObserver).
 *
 * The rows are owned by their task (cascadeOnDelete; tasks are only ever
 * soft-deleted, so a deleted task keeps its assignees for the restore) and
 * exist only for their user (cascadeOnDelete, as for the routine assignees;
 * users are soft-deleted too). The assigner is a user reference
 * (nullOnDelete). down() drops the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_co_assignees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('assigned_at');

            $table->unique(['task_id', 'user_id'], 'task_co_assignees_task_id_user_id_unique');
            $table->index(['user_id', 'task_id'], 'task_co_assignees_user_id_task_id_index');
            $table->index('assigned_by', 'task_co_assignees_assigned_by_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_co_assignees');
    }
};
