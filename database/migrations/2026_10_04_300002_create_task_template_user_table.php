<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The assignees of a routine task (decision D-22): on each scheduled day the
 * CRM creates one task per row here.
 *
 * Each user appears once per routine (unique pair). A row exists only for its
 * routine and its user, so it goes with either (CASCADE on both sides) — a
 * deleted routine leaves no assignee rows behind, and a user removed from the
 * database leaves the routines they were on. RoutineTaskService writes the
 * rows; the `user_id` index answers "which routines is this user on".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_template_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_template_id')->constrained('task_templates')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->unique(['task_template_id', 'user_id'], 'task_template_user_task_template_id_user_id_unique');
            $table->index('user_id', 'task_template_user_user_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_template_user');
    }
};
