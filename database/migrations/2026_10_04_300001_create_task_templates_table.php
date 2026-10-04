<?php

declare(strict_types=1);

use App\Enums\RoutineFrequency;
use App\Enums\TaskPriority;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Routine tasks — recurring task templates for routine weekly work
 * (decision D-22).
 *
 * A routine is configuration, not a business entity: it carries no soft
 * deletes, and deleting one never touches the tasks it already created (their
 * `template_id` is cleared by the foreign key on `tasks`). Its assignees live
 * in `task_template_user`.
 *
 * `title` is sized like `tasks.title`, which every occurrence copies;
 * `priority` and `frequency` are code enums (TaskPriority, RoutineFrequency),
 * CHECK-constrained (A-4). The schedule is `weekday` (0 = Sunday … 6 =
 * Saturday) for a weekly routine or `day_of_month` (1–28, so every month has
 * the day) for a monthly one — exactly one of them, enforced by a CHECK — plus
 * `due_time` (the time of day the tasks are due, organisation time, default
 * 17:00) and `due_in_days` (0–30 days after the scheduled day). `next_run_on`
 * is the organisation calendar day the hourly `tasks:generate-routines` run
 * produces the tasks next: set exactly while the routine is active (CHECK),
 * advanced in the transaction that produces a day's tasks and recomputed by
 * RoutineTaskService when the schedule changes or the routine is resumed.
 * `last_run_on` is the day whose tasks were produced last, so a day is never
 * produced twice — not even after a same-day schedule change or resume.
 * `assigned_by` is whoever last saved the routine: the tasks are
 * handed out in their name (D-14) and a departed user's routine keeps running
 * (nullOnDelete, the user-reference default of CLAUDE.md section 3). The
 * `(is_active, next_run_on)` index serves the hourly pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('priority', 32)->default(TaskPriority::Medium->value);
            $table->string('frequency', 32);
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->time('due_time')->default('17:00:00');
            $table->unsignedTinyInteger('due_in_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->date('next_run_on')->nullable();
            $table->date('last_run_on')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'next_run_on'], 'task_templates_is_active_next_run_on_index');
            $table->index('frequency', 'task_templates_frequency_index');
            $table->index('assigned_by', 'task_templates_assigned_by_index');
        });

        EnumCheck::apply('task_templates', 'priority', TaskPriority::class);
        EnumCheck::apply('task_templates', 'frequency', RoutineFrequency::class);

        DB::statement('ALTER TABLE `task_templates` ADD CONSTRAINT `task_templates_weekday_check` CHECK (`weekday` IS NULL OR `weekday` BETWEEN 0 AND 6)');
        DB::statement('ALTER TABLE `task_templates` ADD CONSTRAINT `task_templates_day_of_month_check` CHECK (`day_of_month` IS NULL OR `day_of_month` BETWEEN 1 AND 28)');
        DB::statement('ALTER TABLE `task_templates` ADD CONSTRAINT `task_templates_due_in_days_check` CHECK (`due_in_days` BETWEEN 0 AND 30)');
        DB::statement(sprintf(
            'ALTER TABLE `task_templates` ADD CONSTRAINT `task_templates_schedule_check` CHECK ('
            .'(`frequency` = %s AND `weekday` IS NOT NULL AND `day_of_month` IS NULL)'
            .' OR (`frequency` = %s AND `day_of_month` IS NOT NULL AND `weekday` IS NULL))',
            DB::getPdo()->quote(RoutineFrequency::Weekly->value),
            DB::getPdo()->quote(RoutineFrequency::Monthly->value),
        ));
        DB::statement('ALTER TABLE `task_templates` ADD CONSTRAINT `task_templates_next_run_on_check` CHECK ((`is_active` = 1 AND `next_run_on` IS NOT NULL) OR (`is_active` = 0 AND `next_run_on` IS NULL))');
    }

    public function down(): void
    {
        if (Schema::hasTable('task_templates')) {
            EnumCheck::drop('task_templates', 'frequency');
            EnumCheck::drop('task_templates', 'priority');
        }

        // The range and schedule CHECKs are dropped together with the table.
        Schema::dropIfExists('task_templates');
    }
};
