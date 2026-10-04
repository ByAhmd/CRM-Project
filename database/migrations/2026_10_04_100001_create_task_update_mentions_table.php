<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a task comment mentions (decision D-20): one row per comment and
 * mentioned user, written by TaskService::comment() inside the comment's own
 * transaction and never edited or deleted — append-only on the model, like
 * the thread it annotates (task_updates).
 *
 * A mention makes the user a participant of the task: TaskPolicy::view and
 * TaskResource::constrainToReadable() ask "which tasks was this user
 * mentioned on", so `task_id` is denormalised from the comment and
 * `(user_id, task_id)` answers it from the index alone, without joining
 * task_updates; `task_id` alone serves "who was mentioned on this task" (the
 * recipients of a later comment). A comment mentions a user once
 * (`(task_update_id, user_id)` is unique).
 *
 * The rows are owned by their comment and their task (cascadeOnDelete;
 * tasks are only ever soft-deleted, so the mentions — and the access they
 * give — survive a delete and a restore) and exist only for the mentioned
 * user (cascadeOnDelete; users are soft-deleted too, so in practice a
 * mention outlives its account like the comment that holds it).
 * `created_at` is the business moment of the mention, a DATETIME like every
 * other moment the design types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_update_mentions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_update_id')->constrained('task_updates')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('created_at');

            $table->unique(['task_update_id', 'user_id'], 'task_update_mentions_task_update_id_user_id_unique');
            $table->index(['user_id', 'task_id'], 'task_update_mentions_user_id_task_id_index');
            $table->index('task_id', 'task_update_mentions_task_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_update_mentions');
    }
};
