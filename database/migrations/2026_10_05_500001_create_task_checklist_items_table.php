<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The checklist of a task (decision D-25): an ordered list of steps, each a
 * line of up to 255 characters, at most 50 per task.
 *
 * Written by TaskService only: the structure (add, rename, reorder, remove)
 * by whoever may edit the task, the ticks by whoever may report on it. A row
 * is owned by its task (cascadeOnDelete; tasks are only ever soft-deleted, so
 * a checklist survives a delete and a restore). A tick keeps who made it and
 * when: `completed_at` is the business moment, a DATETIME like every other
 * moment the design types, and `completed_by` keeps the tick when the
 * account goes (nullOnDelete, the user-reference default of CLAUDE.md
 * section 3). The (task_id, position) index serves the ordered read and
 * leads with the foreign key; `completed_by` has its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('body', 255);
            $table->unsignedSmallInteger('position')->default(0);
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['task_id', 'position'], 'task_checklist_items_task_id_position_index');
            $table->index('completed_by', 'task_checklist_items_completed_by_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_checklist_items');
    }
};
