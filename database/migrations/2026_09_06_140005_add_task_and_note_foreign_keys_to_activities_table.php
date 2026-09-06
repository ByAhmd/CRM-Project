<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the loop opened by the activities migration (decision A-10): the
 * `task_id` and `note_id` columns were created as plain indexed integers
 * because the tasks and notes tables did not exist yet. Both tables do now
 * (tasks at 140002, notes at 140003), so the foreign keys are added with
 * SET NULL — removing a task or a note keeps the timeline entry it produced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->foreign('task_id', 'activities_task_id_foreign')->references('id')->on('tasks')->nullOnDelete();
            $table->foreign('note_id', 'activities_note_id_foreign')->references('id')->on('notes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropForeign('activities_note_id_foreign');
            $table->dropForeign('activities_task_id_foreign');
        });
    }
};
