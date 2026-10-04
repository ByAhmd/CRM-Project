<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The routine a task was created from (decision D-22).
 *
 * Written by RoutineTaskService only (not fillable on the model). Deleting a
 * routine never deletes the tasks it already created: the link is cleared
 * instead (nullOnDelete) and the tasks carry on as ordinary handed-out tasks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('template_id')->nullable()->after('series_id')->constrained('task_templates')->nullOnDelete();
            $table->index('template_id', 'tasks_template_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropForeign(['template_id']);
            $table->dropIndex('tasks_template_id_index');
            $table->dropColumn('template_id');
        });
    }
};
