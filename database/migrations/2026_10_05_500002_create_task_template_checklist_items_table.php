<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The checklist of a routine task (decisions D-22, D-25): the steps copied,
 * unticked and in this order, into every task the routine creates.
 *
 * Configuration owned by its routine (cascadeOnDelete — deleting a routine
 * removes its checklist; the tasks it already created keep their own copy).
 * Written by RoutineTaskService only. There are no ticks here: a routine's
 * checklist is never worked on, only copied. The (task_template_id, position)
 * index serves the ordered read and leads with the foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_template_checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_template_id')->constrained('task_templates')->cascadeOnDelete();
            $table->string('body', 255);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['task_template_id', 'position'], 'task_template_checklist_items_template_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_template_checklist_items');
    }
};
