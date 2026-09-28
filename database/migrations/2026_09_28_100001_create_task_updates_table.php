<?php

declare(strict_types=1);

use App\Enums\TaskStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The progress log of a task (decision D-14, amended 2026-09-28): one
 * immutable, timestamped row per report its assignee makes — starting the
 * task, posting a progress update, completing it — and per reopening.
 *
 * Written by TaskService only and never edited (append-only on the model).
 * `status` is the status the entry moved the task to (null for a plain
 * progress note), CHECK-constrained to TaskStatus; `body` is the plain text
 * the author wrote. The rows are owned by their task (cascadeOnDelete; tasks
 * are only ever soft-deleted, so the log survives a delete and a restore) and
 * keep their text when the author's account goes (nullOnDelete, the
 * user-reference default of CLAUDE.md section 3). `created_at` is the
 * business moment of the entry, a DATETIME like every other moment the
 * design types (no 2038 range, no session time-zone conversion).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->nullable();
            $table->text('body')->nullable();
            $table->dateTime('created_at');

            $table->index(['task_id', 'created_at'], 'task_updates_task_id_created_at_index');
            $table->index('user_id', 'task_updates_user_id_index');
            $table->index('status', 'task_updates_status_index');
        });

        EnumCheck::apply('task_updates', 'status', TaskStatus::class);
    }

    public function down(): void
    {
        if (Schema::hasTable('task_updates')) {
            EnumCheck::drop('task_updates', 'status');
        }

        Schema::dropIfExists('task_updates');
    }
};
